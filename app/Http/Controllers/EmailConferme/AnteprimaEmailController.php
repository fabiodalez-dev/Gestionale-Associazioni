<?php

namespace App\Http\Controllers\EmailConferme;

use App\Http\Controllers\Controller;
use App\Http\Requests\EmailConferme\InviaProvaEmailRequest;
use App\Mail\EmailConferme\ProvaEmail;
use App\Models\Associazione;
use App\Models\Utente;
use App\Services\Email\EmailAnteprimaService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Centro anteprime email (f.20 PIANO-VITA-SOCIALE): elenco di tutte le
 * notifiche di sistema, anteprima HTML renderizzata su dati di esempio
 * (modelli in memoria del tenant corrente) e invio di prova all'email
 * dell'amministratore loggato.
 *
 * Permessi spatie del modulo email: email.view per consultare,
 * email.create per l'invio di prova (Form Request).
 */
class AnteprimaEmailController extends Controller
{
    public function __construct(private readonly EmailAnteprimaService $anteprime) {}

    /**
     * Elenco notifiche + anteprima della selezionata.
     */
    public function index(Request $request): View
    {
        $utente = $this->utenteAutorizzato($request);
        $associazione = $this->associazioneCorrente($utente);

        $catalogo = $this->anteprime->catalogo();

        $codice = (string) $request->query('codice', (string) array_key_first($catalogo));

        abort_unless(array_key_exists($codice, $catalogo), 404);

        $errore = null;
        $anteprima = null;

        try {
            $anteprima = $this->anteprime->anteprima($codice, $associazione);
        } catch (\Throwable $e) {
            Log::error('AnteprimaEmailController: rendering anteprima fallito', [
                'codice' => $codice,
                'error' => $e->getMessage(),
            ]);
            $errore = 'Impossibile generare l\'anteprima di questa notifica.';
        }

        return view('email-conferme.anteprime', [
            'catalogo' => $catalogo,
            'codice' => $codice,
            'anteprima' => $anteprima,
            'errore' => $errore,
            'puoInviareProva' => $utente->can('email.create'),
        ]);
    }

    /**
     * HTML grezzo dell'anteprima (contenuto dell'iframe).
     */
    public function show(Request $request, string $codice): Response
    {
        $utente = $this->utenteAutorizzato($request);
        $associazione = $this->associazioneCorrente($utente);

        abort_unless(array_key_exists($codice, $this->anteprime->catalogo()), 404);

        try {
            $anteprima = $this->anteprime->anteprima($codice, $associazione);
        } catch (\Throwable $e) {
            Log::error('AnteprimaEmailController: rendering anteprima fallito', [
                'codice' => $codice,
                'error' => $e->getMessage(),
            ]);

            return response('<p style="font-family:sans-serif;color:#888;padding:20px;">Anteprima non disponibile.</p>');
        }

        return response($anteprima['html']);
    }

    /**
     * "Invia prova a me": inoltra l'anteprima all'email dell'admin loggato.
     */
    public function prova(InviaProvaEmailRequest $request, string $codice): RedirectResponse
    {
        /** @var Utente $utente */
        $utente = $request->user();
        $associazione = $this->associazioneCorrente($utente);

        abort_unless(array_key_exists($codice, $this->anteprime->catalogo()), 404);

        if (blank($utente->email)) {
            return redirect()
                ->route('email-anteprime.index', ['codice' => $codice])
                ->with('error', 'Il tuo utente non ha un indirizzo email configurato.');
        }

        try {
            $anteprima = $this->anteprime->anteprima($codice, $associazione);

            Mail::to($utente->email)->send(new ProvaEmail($anteprima['subject'], $anteprima['html']));
        } catch (\Throwable $e) {
            Log::error('AnteprimaEmailController: invio di prova fallito', [
                'codice' => $codice,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('email-anteprime.index', ['codice' => $codice])
                ->with('error', 'Invio di prova non riuscito: controlla la configurazione email.');
        }

        return redirect()
            ->route('email-anteprime.index', ['codice' => $codice])
            ->with('success', 'Email di prova inviata a '.$utente->email.'.');
    }

    /**
     * Guard di lettura: permesso spatie email.view (stesso del modulo email).
     */
    protected function utenteAutorizzato(Request $request): Utente
    {
        $utente = $request->user();

        abort_unless($utente instanceof Utente && $utente->can('email.view'), 403);

        return $utente;
    }

    /**
     * Tenant corrente per i dati di esempio (middleware 'tenant' attivo:
     * associazione dell'utente o selezionata in sessione dal super admin).
     */
    protected function associazioneCorrente(Utente $utente): Associazione
    {
        $associazioneId = $utente->associazione_id ?: session('associazione_id');

        /** @var Associazione|null $associazione */
        $associazione = $associazioneId !== null ? Associazione::find($associazioneId) : null;

        abort_if($associazione === null, 404);

        return $associazione;
    }
}
