<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\IscrizioneSocioRequest;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Utente;
use App\Notifications\Iscrizioni\ConfermaEmailIscrizioneNotification;
use App\Notifications\Iscrizioni\ConfermaRichiestaInviataNotification;
use App\Notifications\Iscrizioni\RichiestaIscrizioneRicevutaNotification;
use App\Services\Iscrizioni\FormIscrizioneService;
use App\Services\Iscrizioni\IscrizioneException;
use App\Support\Relazioni\RelazioniReferral;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * Iscrizione socio dal mini-sito pubblico: /a/{slug}/iscriviti
 * (PIANO-ISCRIZIONI §Rotte e UI, frontend pubblico).
 *
 * GET (guard account + verificato):
 * - iscrizioni_socio_aperte=false → pagina "iscrizioni chiuse";
 * - account già collegato a un socio → redirect a tessera & quote;
 * - richiesta in_attesa → pagina di stato;
 * - altrimenti form renderizzato dallo schema di FormIscrizioneService
 *   (con l'eventuale motivo dell'ultimo rifiuto, per riprovare).
 *
 * POST (honeypot + throttle 5/m): valida con le rules() dinamiche.
 * Con account: salva via FormIscrizioneService, notifica gli admin
 * best-effort e reindirizza alla pagina di stato (o alla tessera in
 * modalità diretta). Da ospite: nessun socio subito, parte l'email con il
 * link firmato (GET ?conferma=...) e la richiesta nasce solo al clic.
 */
class IscrizioneController extends Controller
{
    public function __construct(private readonly FormIscrizioneService $form) {}

    /** Risposta a un ospite dopo l'invio del modulo, qualunque sia l'esito (niente oracolo). */
    public const MESSAGGIO_CONFERMA_OSPITE = 'Ci siamo quasi! Ti abbiamo inviato un\'email: apri il link per confermare l\'indirizzo e inviare la richiesta di iscrizione (il link vale '.FormIscrizioneService::ORE_VALIDITA_CONFERMA.' ore).';

    public function show(Request $request, Associazione $associazione): View|RedirectResponse
    {
        // Link di conferma dell'email ricevuto da un ospite (firmato e temporaneo)
        if ($request->query->has('conferma')) {
            return $this->conferma($request, $associazione);
        }

        /** @var ?Account $account */
        $account = $request->user('account');

        if (! $associazione->iscrizioni()->iscrizioni_socio_aperte) {
            return view('pubblico.iscrizione.chiusa', [
                'associazione' => $associazione,
                'account' => $account,
            ]);
        }

        if ($account?->socio_id !== null) {
            return redirect()
                ->route('pubblico.account.tessera', ['associazione' => $associazione->slug])
                ->with('success', 'Sei già socio: trovi tessera e quote qui.');
        }

        $richiesta = $account !== null ? $this->ultimaRichiesta($associazione, $account) : null;

        if ($richiesta !== null && $richiesta->isInAttesa()) {
            return view('pubblico.iscrizione.stato', [
                'associazione' => $associazione,
                'account' => $account,
                'richiesta' => $richiesta,
            ]);
        }

        return view('pubblico.iscrizione.form', [
            'associazione' => $associazione,
            'account' => $account,
            'schema' => $this->form->schema($associazione),
            'ultimaRifiutata' => $richiesta !== null && $richiesta->isRifiutata() ? $richiesta : null,
        ]);
    }

    public function store(IscrizioneSocioRequest $request, Associazione $associazione): RedirectResponse
    {
        /** @var ?Account $account */
        $account = $request->user('account');

        if ($account === null) {
            return $this->storeOspite($request, $associazione);
        }

        try {
            $richiesta = $this->form->salva($account, $request->validated(), $associazione);
        } catch (IscrizioneException $e) {
            // Errore di dominio (chiuse / già socio / doppia richiesta):
            // messaggio in italiano pensato per l'utente, niente stack trace
            return redirect()
                ->route('pubblico.iscriviti', ['associazione' => $associazione->slug])
                ->withErrors(['iscrizione' => $e->getMessage()]);
        }

        $this->notificaAdmin($richiesta);
        $this->confermaRichiedente($richiesta, $account);

        if ($richiesta->isApprovata()) {
            // Modalità diretta con account: già collegato al nuovo socio
            return redirect()
                ->route('pubblico.account.tessera', ['associazione' => $associazione->slug])
                ->with('success', 'Benvenuto/a! La tua iscrizione è stata registrata: ora sei socio di '.$associazione->nome.'.');
        }

        return redirect()
            ->route('pubblico.iscriviti', ['associazione' => $associazione->slug])
            ->with('success', 'Richiesta di iscrizione inviata! L\'associazione la valuterà e riceverai una risposta via email.');
    }

    /**
     * Ospite senza account: nessun socio finché l'email non è confermata.
     * Stessa risposta se la conferma parte o no (email già di un socio, richiesta
     * già in attesa, troppi invii): il modulo pubblico non rivela chi è iscritto.
     */
    protected function storeOspite(IscrizioneSocioRequest $request, Associazione $associazione): RedirectResponse
    {
        $dati = $request->validated();

        try {
            $referral = $request->input('referral_codice') ?? $request->input('ref') ?? $request->session()->get(RelazioniReferral::SESSION_KEY);
            $pendente = $this->form->preparaConfermaOspite($dati, $associazione, $request->ip(), is_string($referral) ? $referral : null);
        } catch (IscrizioneException $e) {
            // Solo "iscrizioni chiuse": non dice nulla sull'email indicata
            return redirect()
                ->route('pubblico.iscriviti', ['associazione' => $associazione->slug])
                ->withErrors(['iscrizione' => $e->getMessage()]);
        }

        if ($pendente !== null) {
            try {
                Notification::route('mail', $pendente->email)->notify(new ConfermaEmailIscrizioneNotification(
                    $associazione,
                    $this->form->linkConferma($pendente, $associazione),
                    Str::limit(trim((string) ($dati['nome'] ?? '')), 50, ''),
                ));
            } catch (\Throwable $e) {
                Log::error('Iscrizione ospite: email di conferma non inviata', [
                    'associazione_id' => $associazione->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()
            ->route('pubblico.home.show', ['associazione' => $associazione->slug])
            ->with('success', self::MESSAGGIO_CONFERMA_OSPITE);
    }

    /**
     * Clic sul link di conferma: ora la richiesta nasce davvero (socio "In
     * Attesa", o attivo in modalità diretta) e partono gli avvisi alla
     * segreteria e il riepilogo al richiedente. Chi apre il link possiede
     * l'email, quindi qui gli esiti di dominio sono espliciti.
     */
    protected function conferma(Request $request, Associazione $associazione): RedirectResponse
    {
        $conferma = $request->query('conferma');

        try {
            if (! is_string($conferma) || ! $request->hasValidSignature()) {
                throw IscrizioneException::confermaNonValida();
            }

            $richiesta = $this->form->confermaOspite($conferma, $associazione);
        } catch (IscrizioneException $e) {
            return redirect()
                ->route('pubblico.iscriviti', ['associazione' => $associazione->slug])
                ->withErrors(['iscrizione' => $e->getMessage()]);
        }

        $this->notificaAdmin($richiesta);
        $this->confermaRichiedente($richiesta, null);

        return redirect()
            ->route('pubblico.home.show', ['associazione' => $associazione->slug])
            ->with('success', $richiesta->isApprovata()
                ? 'Email confermata. Benvenuto/a! La tua iscrizione a '.$associazione->nome.' è stata registrata: riceverai le comunicazioni via email.'
                : 'Email confermata: la tua richiesta di iscrizione è stata inviata. L\'associazione la valuterà e riceverai una risposta via email.');
    }

    /**
     * Ultima richiesta di iscrizione dell'account sul tenant corrente.
     */
    protected function ultimaRichiesta(Associazione $associazione, Account $account): ?RichiestaIscrizione
    {
        return RichiestaIscrizione::query()
            ->forAssociazione($associazione->id)
            ->where('account_id', $account->id)
            ->latest()
            ->first();
    }

    /**
     * Notifica gli admin attivi del tenant della nuova richiesta
     * (PIANO-ISCRIZIONI §Notifiche, RichiestaIscrizioneRicevutaNotification).
     * Best-effort, FUORI dalla transazione di FormIscrizioneService::salva():
     * se l'invio fallisce la richiesta dell'utente NON deve fallire.
     */
    protected function notificaAdmin(RichiestaIscrizione $richiesta): void
    {
        try {
            $admins = Utente::query()
                ->where('associazione_id', $richiesta->associazione_id)
                ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
                ->where('attivo', true)
                ->get();

            if ($admins->isNotEmpty()) {
                Notification::send($admins, new RichiestaIscrizioneRicevutaNotification($richiesta));
            }
        } catch (\Throwable $e) {
            Log::error('Iscrizione: notifica admin fallita', [
                'richiesta_id' => $richiesta->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Conferma immediata "richiesta ricevuta" al richiedente con il
     * riepilogo dei dati inviati (f.19 PIANO-VITA-SOCIALE): all'Account
     * autenticato oppure, per gli ospiti, via mail all'indirizzo del
     * socio preiscritto. Best-effort: un errore di invio non deve mai
     * far fallire la richiesta.
     */
    protected function confermaRichiedente(RichiestaIscrizione $richiesta, ?Account $account): void
    {
        try {
            if ($account !== null) {
                $account->notify(new ConfermaRichiestaInviataNotification($richiesta));

                return;
            }

            $email = $richiesta->socio()
                ->withoutGlobalScope('associazione')
                ->withTrashed()
                ->first()
                ?->email;

            if (filled($email)) {
                Notification::route('mail', $email)
                    ->notify(new ConfermaRichiestaInviataNotification($richiesta));
            }
        } catch (\Throwable $e) {
            Log::error('Iscrizione: conferma al richiedente fallita', [
                'richiesta_id' => $richiesta->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
