<?php

namespace App\Http\Controllers\Eventi;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Utente;
use App\Notifications\Prenotazioni\BigliettoPrenotazioneNotification;
use App\Services\Prenotazioni\PrenotazioneException;
use App\Services\Prenotazioni\PrenotazioneService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Tab "Prenotazioni" di un evento (admin, docs/PIANO-PRENOTAZIONI.md
 * sezione Admin): lista con account/posti/stato/pagamento/check-in,
 * conferma manuale dei pagamenti offline, annullo (con promozione
 * automatica della lista d'attesa), promozione manuale FIFO, reinvio
 * del biglietto (best-effort) ed export CSV.
 *
 * Tutte le transizioni di stato passano dal PrenotazioneService
 * (mutex per-evento): qui non si toccano mai i posti direttamente.
 * Un evento di un altro tenant produce 404 (binding scoped).
 */
class PrenotazioneEventoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly PrenotazioneService $prenotazioni) {}

    /**
     * Lista prenotazioni dell'evento con filtro stato e conteggi in testa
     * (posti confermati / riservati / in coda rispetto alla capienza).
     */
    public function index(Request $request, Evento $evento): View
    {
        $this->authorize('view', $evento);

        $stato = (string) $request->query('stato', 'all');
        if (! in_array($stato, Prenotazione::STATI, true)) {
            $stato = 'all';
        }

        $prenotazioni = $evento->prenotazioni()
            ->with([
                'account' => fn ($q) => $q->withTrashed(),
                'checkinUtente',
            ])
            ->when($stato !== 'all', fn ($q) => $q->where('stato', $stato))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('eventi.prenotazioni', [
            'evento' => $evento,
            'prenotazioni' => $prenotazioni,
            'conteggi' => $this->conteggi($evento),
            'stato' => $stato,
            'stati' => Prenotazione::STATI,
        ]);
    }

    /**
     * Conferma manuale di un pagamento ricevuto offline (contanti,
     * bonifico, ...): delega a onPagamentoCompletato, che è idempotente
     * e gestisce anche la riconferma di una prenotazione scaduta
     * (overbooking accettato, visibile in questa stessa lista).
     */
    public function confermaPagamento(Request $request, Evento $evento, string $prenotazione): RedirectResponse
    {
        $this->authorize('update', $evento);

        /** @var Prenotazione $record */
        $record = $evento->prenotazioni()->findOrFail($prenotazione);

        if (! in_array($record->stato, [Prenotazione::STATO_IN_ATTESA_PAGAMENTO, Prenotazione::STATO_SCADUTA], true)
            || $record->pagata_at !== null) {
            return redirect()
                ->route('eventi.prenotazioni.index', $evento)
                ->with('error', 'La prenotazione non è in attesa di pagamento.');
        }

        /** @var Utente $utente */
        $utente = $request->user();

        $this->prenotazioni->onPagamentoCompletato(
            $record->id,
            $evento->associazione_id,
            'offline',
            'manuale-'.$utente->id.'-'.now()->format('YmdHis'),
        );

        return redirect()
            ->route('eventi.prenotazioni.index', $evento)
            ->with('success', "Pagamento registrato: prenotazione {$record->codice} confermata.");
    }

    /**
     * Annullo da admin (sempre consentito dalla macchina a stati):
     * il rilascio dei posti innesca la promozione FIFO della lista
     * d'attesa nella stessa transazione.
     */
    public function annulla(Request $request, Evento $evento, string $prenotazione): RedirectResponse
    {
        $this->authorize('update', $evento);

        $validated = $request->validate([
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        /** @var Prenotazione $record */
        $record = $evento->prenotazioni()->findOrFail($prenotazione);

        /** @var Utente $utente */
        $utente = $request->user();

        try {
            $this->prenotazioni->annulla($record, $utente, $validated['motivo'] ?? null);
        } catch (PrenotazioneException $e) {
            return redirect()
                ->route('eventi.prenotazioni.index', $evento)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('eventi.prenotazioni.index', $evento)
            ->with('success', "Prenotazione {$record->codice} annullata.");
    }

    /**
     * Approvazione di una prenotazione 'in_approvazione' (eventi con
     * richiede_approvazione): gratuito → confermata, a pagamento →
     * in_attesa_pagamento con la finestra dei 30 minuti. Tutto nel
     * mutex per-evento del service.
     */
    public function approvaPrenotazione(Request $request, Evento $evento, string $prenotazione): RedirectResponse
    {
        $this->authorize('update', $evento);

        /** @var Prenotazione $record */
        $record = $evento->prenotazioni()->findOrFail($prenotazione);

        /** @var Utente $utente */
        $utente = $request->user();

        try {
            $record = $this->prenotazioni->approva($record, $utente);
        } catch (PrenotazioneException $e) {
            return redirect()
                ->route('eventi.prenotazioni.index', $evento)
                ->with('error', $e->getMessage());
        }

        $messaggio = $record->stato === Prenotazione::STATO_CONFERMATA
            ? "Prenotazione {$record->codice} approvata e confermata."
            : "Prenotazione {$record->codice} approvata: l'utente ha 30 minuti per completare il pagamento.";

        return redirect()
            ->route('eventi.prenotazioni.index', $evento)
            ->with('success', $messaggio);
    }

    /**
     * Rifiuto di una prenotazione 'in_approvazione' con motivo obbligatorio
     * (comunicato all'account): la prenotazione viene annullata e i posti
     * rilasciati promuovono la lista d'attesa.
     */
    public function rifiutaPrenotazione(Request $request, Evento $evento, string $prenotazione): RedirectResponse
    {
        $this->authorize('update', $evento);

        $validated = $request->validate([
            'motivo' => ['required', 'string', 'max:500'],
        ], [
            'motivo.required' => 'Il motivo del rifiuto è obbligatorio: viene comunicato al richiedente.',
        ]);

        /** @var Prenotazione $record */
        $record = $evento->prenotazioni()->findOrFail($prenotazione);

        /** @var Utente $utente */
        $utente = $request->user();

        try {
            $this->prenotazioni->rifiuta($record, $utente, $validated['motivo']);
        } catch (PrenotazioneException $e) {
            return redirect()
                ->route('eventi.prenotazioni.index', $evento)
                ->with('error', $e->getMessage());
        }

        return redirect()
            ->route('eventi.prenotazioni.index', $evento)
            ->with('success', "Prenotazione {$record->codice} rifiutata: i posti liberati promuovono la lista d'attesa.");
    }

    /**
     * Promozione manuale della lista d'attesa (FIFO stretto, stesso
     * algoritmo dello scheduler): utile dopo un aumento di capienza.
     */
    public function promuovi(Request $request, Evento $evento): RedirectResponse
    {
        $this->authorize('update', $evento);

        $promosse = $this->prenotazioni->promuoviListaAttesa($evento->id);

        $messaggio = count($promosse) === 0
            ? 'Nessuna prenotazione promuovibile: lista d\'attesa vuota o posti insufficienti per la prima in coda.'
            : (count($promosse) === 1
                ? '1 prenotazione promossa dalla lista d\'attesa.'
                : count($promosse).' prenotazioni promosse dalla lista d\'attesa.');

        return redirect()
            ->route('eventi.prenotazioni.index', $evento)
            ->with(count($promosse) > 0 ? 'success' : 'warning', $messaggio);
    }

    /**
     * Reinvio del biglietto via email (best-effort: un errore SMTP non
     * blocca l'admin, viene segnalato senza interrompere il flusso).
     */
    public function reinviaBiglietto(Request $request, Evento $evento, string $prenotazione): RedirectResponse
    {
        $this->authorize('update', $evento);

        /** @var Prenotazione $record */
        $record = $evento->prenotazioni()->findOrFail($prenotazione);

        if ($record->stato !== Prenotazione::STATO_CONFERMATA) {
            return redirect()
                ->route('eventi.prenotazioni.index', $evento)
                ->with('error', 'Il biglietto si può reinviare solo per le prenotazioni confermate.');
        }

        $account = $record->account()->first();

        if ($account === null || ! $account->attivo || blank($account->email)) {
            return redirect()
                ->route('eventi.prenotazioni.index', $evento)
                ->with('error', 'L\'account della prenotazione non è più raggiungibile via email.');
        }

        try {
            $account->notify(new BigliettoPrenotazioneNotification($record));
        } catch (\Throwable $e) {
            Log::warning('Reinvio biglietto fallito', [
                'prenotazione_id' => $record->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('eventi.prenotazioni.index', $evento)
                ->with('error', 'Invio email non riuscito: riprova più tardi o verifica le impostazioni SMTP.');
        }

        return redirect()
            ->route('eventi.prenotazioni.index', $evento)
            ->with('success', "Biglietto {$record->codice} reinviato a {$account->email}.");
    }

    /**
     * Export CSV delle prenotazioni (rispetta il filtro stato corrente).
     * Separatore ';' e BOM UTF-8 per Excel italiano (pattern partecipanti).
     */
    public function export(Request $request, Evento $evento): StreamedResponse
    {
        $this->authorize('view', $evento);

        $stato = (string) $request->query('stato', 'all');
        if (! in_array($stato, Prenotazione::STATI, true)) {
            $stato = 'all';
        }

        $prenotazioni = $evento->prenotazioni()
            ->with([
                'account' => fn ($q) => $q->withTrashed(),
                'checkinUtente',
            ])
            ->when($stato !== 'all', fn ($q) => $q->where('stato', $stato))
            ->orderByDesc('created_at')
            ->get();

        $filename = 'prenotazioni-'.Str::slug($evento->titolo ?: 'evento').'.csv';

        return response()->streamDownload(function () use ($prenotazioni) {
            $out = fopen('php://output', 'w');
            // BOM UTF-8 per Excel
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'Codice', 'Cognome', 'Nome', 'Email', 'Posti', 'Stato',
                'Importo', 'Gateway', 'Pagata il', 'Check-in', 'Operatore check-in', 'Creata il',
            ], ';');

            foreach ($prenotazioni as $p) {
                fputcsv($out, [
                    $p->codice,
                    $p->account?->cognome,
                    $p->account?->nome,
                    $p->account?->email,
                    $p->posti,
                    $p->stato,
                    number_format((float) $p->importo_totale, 2, ',', ''),
                    $p->gateway,
                    $p->pagata_at?->format('d/m/Y H:i'),
                    $p->checkin_at?->format('d/m/Y H:i'),
                    $p->checkinUtente?->nome_completo,
                    $p->created_at?->format('d/m/Y H:i'),
                ], ';');
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Conteggi di testa: posti confermati / riservati / in coda + numero
     * prenotazioni per stato (sola lettura, fuori dal mutex).
     *
     * @return array<string, int|null>
     */
    private function conteggi(Evento $evento): array
    {
        $posti = $evento->prenotazioni()
            ->selectRaw('stato, SUM(posti) as posti, COUNT(*) as numero')
            ->groupBy('stato')
            ->get()
            ->keyBy('stato');

        return [
            'posti_confermati' => (int) ($posti[Prenotazione::STATO_CONFERMATA]->posti ?? 0),
            // Riservati = in attesa di pagamento + in approvazione (entrambi consumano posti)
            'posti_riservati' => (int) ($posti[Prenotazione::STATO_IN_ATTESA_PAGAMENTO]->posti ?? 0)
                + (int) ($posti[Prenotazione::STATO_IN_APPROVAZIONE]->posti ?? 0),
            'posti_in_coda' => (int) ($posti[Prenotazione::STATO_LISTA_ATTESA]->posti ?? 0),
            'numero_confermate' => (int) ($posti[Prenotazione::STATO_CONFERMATA]->numero ?? 0),
            'numero_riservate' => (int) ($posti[Prenotazione::STATO_IN_ATTESA_PAGAMENTO]->numero ?? 0)
                + (int) ($posti[Prenotazione::STATO_IN_APPROVAZIONE]->numero ?? 0),
            'numero_in_coda' => (int) ($posti[Prenotazione::STATO_LISTA_ATTESA]->numero ?? 0),
            'numero_da_approvare' => (int) ($posti[Prenotazione::STATO_IN_APPROVAZIONE]->numero ?? 0),
            'capienza' => $evento->capienza,
        ];
    }
}
