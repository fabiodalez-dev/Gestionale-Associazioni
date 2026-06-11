<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\IscrizioneSocioRequest;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\RichiestaIscrizione;
use App\Models\Utente;
use App\Notifications\Iscrizioni\RichiestaIscrizioneRicevutaNotification;
use App\Services\Iscrizioni\FormIscrizioneService;
use App\Services\Iscrizioni\IscrizioneException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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
 * POST (honeypot + throttle 5/m): valida con le rules() dinamiche,
 * salva via FormIscrizioneService, notifica gli admin best-effort e
 * reindirizza alla pagina di stato (o alla tessera in modalità diretta).
 */
class IscrizioneController extends Controller
{
    public function __construct(private readonly FormIscrizioneService $form) {}

    public function show(Request $request, Associazione $associazione): View|RedirectResponse
    {
        /** @var ?Account $account */
        $account = $request->user('account');

        if (! $associazione->iscrizioni_socio_aperte) {
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

        if ($richiesta->isApprovata() && $account !== null) {
            // Modalità diretta con account: già collegato al nuovo socio
            return redirect()
                ->route('pubblico.account.tessera', ['associazione' => $associazione->slug])
                ->with('success', 'Benvenuto/a! La tua iscrizione è stata registrata: ora sei socio di '.$associazione->nome.'.');
        }

        if ($richiesta->isApprovata()) {
            // Modalità diretta da ospite: nessuna area riservata da mostrare
            return redirect()
                ->route('pubblico.home.show', ['associazione' => $associazione->slug])
                ->with('success', 'Benvenuto/a! La tua iscrizione a '.$associazione->nome.' è stata registrata: riceverai le comunicazioni via email.');
        }

        return redirect()
            ->route($account !== null ? 'pubblico.iscriviti' : 'pubblico.home.show', ['associazione' => $associazione->slug])
            ->with('success', 'Richiesta di iscrizione inviata! L\'associazione la valuterà e riceverai una risposta via email.');
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
}
