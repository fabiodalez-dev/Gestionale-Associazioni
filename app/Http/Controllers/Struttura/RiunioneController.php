<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\SalvaRiunioneRequest;
use App\Http\Requests\Struttura\SalvaVotazioneRiunioneRequest;
use App\Models\Carica;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Spazio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotazioneRiunione;
use App\Notifications\Struttura\ConvocazioneRiunioneNotification;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Riunioni del direttivo (f.17-18 del piano "Organizzazione 3"):
 * - CRUD con ordine del giorno strutturato (odg json [{punto, descrizione}]);
 * - inviti agli intestatari delle cariche attive (round 1) + utenti admin,
 *   con convocazione email brandizzata (ShouldQueue) e stato presenza;
 * - votazioni per punto sulle riunioni svolte (f.18);
 * - "Genera bozza verbale" → crea un Verbale precompilato con numerazione
 *   automatica (modulo verbali esistente).
 *
 * Governance: policy RiunionePolicy, famiglia di permessi struttura.*.
 */
class RiunioneController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Riunione::class);

        $search = trim((string) $request->query('search', ''));
        $stato = (string) $request->query('stato', 'all');

        $riunioni = Riunione::query()
            ->with(['spazio', 'verbale'])
            ->withCount(['partecipanti', 'votazioni'])
            ->when($search !== '', fn ($q) => $q->where('titolo', 'like', "%{$search}%"))
            ->when(
                $stato !== '' && $stato !== 'all' && in_array($stato, Riunione::STATI, true),
                fn ($q) => $q->where('stato', $stato)
            )
            ->orderByDesc('data_ora')
            ->paginate(20)
            ->withQueryString();

        $spazi = Spazio::query()->where('attivo', true)->orderBy('nome')->get(['id', 'nome']);

        return view('struttura.riunioni.index', [
            'riunioni' => $riunioni,
            'search' => $search,
            'stato' => $stato,
            'stati' => Riunione::STATI,
            'spazi' => $spazi,
        ]);
    }

    public function show(Riunione $riunione): View
    {
        $this->authorize('view', $riunione);

        $riunione->load([
            'spazio',
            'verbale',
            'partecipanti' => fn ($q) => $q->orderBy('carica_ruolo'),
            'partecipanti.socio' => fn ($q) => $q->withTrashed(),
            'partecipanti.utente',
            'votazioni' => fn ($q) => $q->orderBy('punto_indice'),
        ]);

        // Votazioni indicizzate per punto OdG (per il form per-punto, f.18)
        $votazioniPerPunto = $riunione->votazioni->keyBy('punto_indice');

        $spazi = Spazio::query()->where('attivo', true)->orderBy('nome')->get(['id', 'nome']);

        return view('struttura.riunioni.show', [
            'riunione' => $riunione,
            'votazioniPerPunto' => $votazioniPerPunto,
            'stati' => Riunione::STATI,
            'esiti' => VotazioneRiunione::ESITI,
            'spazi' => $spazi,
        ]);
    }

    public function store(SalvaRiunioneRequest $request): RedirectResponse
    {
        $riunione = Riunione::create($request->validated());

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Riunione creata con successo!');
    }

    public function update(SalvaRiunioneRequest $request, Riunione $riunione): RedirectResponse
    {
        $riunione->update($request->validated());

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Riunione aggiornata con successo!');
    }

    public function destroy(Riunione $riunione): RedirectResponse
    {
        $this->authorize('delete', $riunione);

        $riunione->delete();

        return redirect()->route('riunioni.index')->with('success', 'Riunione eliminata con successo!');
    }

    /**
     * Invita gli intestatari delle cariche attive (round 1) e gli utenti
     * admin del tenant come partecipanti, poi invia la convocazione email
     * brandizzata (f.17). Idempotente: gli inviti già presenti non vengono
     * duplicati (unique riunione+socio / riunione+utente).
     */
    public function convoca(Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        if ($riunione->stato === Riunione::STATO_ANNULLATA) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'La riunione è annullata: non è possibile inviare la convocazione.');
        }

        DB::transaction(function () use ($riunione) {
            // Intestatari delle cariche attive (round 1)
            $cariche = Carica::query()->attive()->get();

            foreach ($cariche as $carica) {
                RiunionePartecipante::firstOrCreate(
                    [
                        'riunione_id' => $riunione->id,
                        'socio_id' => $carica->socio_id,
                    ],
                    [
                        'associazione_id' => $riunione->associazione_id,
                        'carica_ruolo' => $carica->ruolo,
                        'stato' => RiunionePartecipante::STATO_INVITATO,
                    ]
                );
            }

            $riunione->forceFill(['convocazione_inviata_at' => now()])->save();
        });

        // Notifica i partecipanti soci (carica) + utenti admin del tenant
        $riunione->load(['partecipanti.socio' => fn ($q) => $q->withTrashed()]);

        $sociDestinatari = $riunione->partecipanti
            ->pluck('socio')
            ->filter(fn ($socio) => $socio !== null && filled($socio->email));

        $utentiAdmin = Utente::query()
            ->where('associazione_id', $riunione->associazione_id)
            ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
            ->where('attivo', true)
            ->get();

        if ($sociDestinatari->isNotEmpty()) {
            Notification::send($sociDestinatari, new ConvocazioneRiunioneNotification($riunione));
        }

        if ($utentiAdmin->isNotEmpty()) {
            Notification::send($utentiAdmin, new ConvocazioneRiunioneNotification($riunione));
        }

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Convocazione inviata ai membri dell\'organo direttivo.');
    }

    /**
     * Aggiorna lo stato di presenza di un partecipante (f.17).
     */
    public function aggiornaPresenza(Request $request, Riunione $riunione, RiunionePartecipante $partecipante): RedirectResponse
    {
        $this->authorize('update', $riunione);

        abort_unless($partecipante->riunione_id === $riunione->id, 404);

        $validated = $request->validate([
            'stato' => ['required', 'in:'.implode(',', RiunionePartecipante::STATI)],
        ]);

        $partecipante->update(['stato' => $validated['stato']]);

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Presenza aggiornata.');
    }

    /**
     * Registra/aggiorna l'esito della votazione su un punto dell'OdG (f.18).
     * Solo per riunioni svolte; un esito per punto (updateOrCreate).
     */
    public function registraVotazione(SalvaVotazioneRiunioneRequest $request, Riunione $riunione): RedirectResponse
    {
        if (! $riunione->isSvolta()) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'Le votazioni si registrano solo sulle riunioni svolte.');
        }

        $dati = $request->validated();

        VotazioneRiunione::updateOrCreate(
            [
                'riunione_id' => $riunione->id,
                'punto_indice' => $dati['punto_indice'],
            ],
            [
                'associazione_id' => $riunione->associazione_id,
                'esito' => $dati['esito'],
                'favorevoli' => $dati['favorevoli'],
                'contrari' => $dati['contrari'],
                'astenuti' => $dati['astenuti'],
                'note' => $dati['note'] ?? null,
            ]
        );

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Esito della votazione registrato.');
    }

    /**
     * Genera una bozza di Verbale precompilato dalla riunione (f.18):
     * data, presenti, punti dell'OdG ed esiti delle votazioni. Numerazione
     * automatica per anno (modulo verbali esistente). Collega il verbale
     * alla riunione (riunioni.verbale_id) per il link nelle Decisioni.
     */
    public function generaVerbale(Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        if (! $riunione->isSvolta()) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'La bozza di verbale si genera solo dalle riunioni svolte.');
        }

        if ($riunione->verbale()->exists()) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'Una bozza di verbale è già stata generata per questa riunione.');
        }

        $riunione->load([
            'partecipanti' => fn ($q) => $q->where('stato', RiunionePartecipante::STATO_CONFERMATO),
            'partecipanti.socio' => fn ($q) => $q->withTrashed(),
            'partecipanti.utente',
            'votazioni' => fn ($q) => $q->orderBy('punto_indice'),
        ]);

        $verbale = DB::transaction(function () use ($riunione): Verbale {
            $anno = (int) $riunione->data_ora->year;

            $verbale = new Verbale([
                'titolo' => $riunione->titolo,
                'data_riunione' => $riunione->data_ora->toDateString(),
                'tipo_riunione' => 'Consiglio Direttivo',
                'presenti' => $this->elencoPresenti($riunione),
                'ordine_del_giorno' => $this->testoOrdineDelGiorno($riunione),
                'deliberazioni' => $this->testoDeliberazioni($riunione),
            ]);
            $verbale->associazione_id = $riunione->associazione_id;
            $verbale->anno = $anno;
            $verbale->numero = Verbale::prossimoNumero($anno);
            $verbale->save();

            $riunione->forceFill(['verbale_id' => $verbale->id])->save();

            return $verbale;
        });

        return redirect()
            ->route('verbali.index', ['edit' => $verbale->id])
            ->with('success', "Bozza di verbale n. {$verbale->numero_completo} generata dalla riunione. Rivedila e approvala.");
    }

    /**
     * Elenco dei presenti (partecipanti confermati) per il verbale.
     */
    private function elencoPresenti(Riunione $riunione): string
    {
        $nomi = $riunione->partecipanti
            ->map(function (RiunionePartecipante $p): string {
                $nome = $p->nomeCompleto();
                $ruolo = $p->carica_ruolo;

                return $ruolo ? "{$nome} ({$ruolo})" : $nome;
            })
            ->filter()
            ->values();

        return $nomi->isEmpty() ? 'Nessun partecipante confermato.' : $nomi->implode("\n");
    }

    /**
     * Testo dell'ordine del giorno numerato per il verbale.
     */
    private function testoOrdineDelGiorno(Riunione $riunione): string
    {
        $odg = is_array($riunione->odg) ? $riunione->odg : [];

        if ($odg === []) {
            return 'Nessun punto all\'ordine del giorno.';
        }

        $righe = [];

        foreach ($odg as $i => $punto) {
            $titolo = is_array($punto) ? (string) ($punto['punto'] ?? '') : (string) $punto;
            $descrizione = is_array($punto) ? trim((string) ($punto['descrizione'] ?? '')) : '';
            $riga = ($i + 1).'. '.$titolo;

            if ($descrizione !== '') {
                $riga .= ' — '.$descrizione;
            }

            $righe[] = $riga;
        }

        return implode("\n", $righe);
    }

    /**
     * Testo delle deliberazioni precompilato dagli esiti delle votazioni (f.18).
     */
    private function testoDeliberazioni(Riunione $riunione): string
    {
        $odg = is_array($riunione->odg) ? $riunione->odg : [];

        if ($riunione->votazioni->isEmpty()) {
            return 'Nessuna votazione registrata.';
        }

        $righe = [];

        foreach ($riunione->votazioni as $votazione) {
            $punto = $odg[$votazione->punto_indice] ?? null;
            $titolo = is_array($punto) ? (string) ($punto['punto'] ?? '') : 'Punto '.($votazione->punto_indice + 1);

            $righe[] = sprintf(
                'Punto %d — %s: %s (favorevoli %d, contrari %d, astenuti %d).%s',
                $votazione->punto_indice + 1,
                $titolo,
                ucfirst($votazione->esito),
                $votazione->favorevoli,
                $votazione->contrari,
                $votazione->astenuti,
                filled($votazione->note) ? ' '.$votazione->note : ''
            );
        }

        return implode("\n", $righe);
    }
}
