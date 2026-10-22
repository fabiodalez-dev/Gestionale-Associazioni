<?php

namespace App\Http\Controllers\Privacy;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Socio;
use App\Services\Privacy\ArchivioDatiPersonali;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Spatie\PersonalDataExport\Jobs\CreatePersonalDataExportJob;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Archivio dei dati personali (GDPR artt. 15 e 20) con
 * spatie/laravel-personal-data-export:
 * - socio (area soci) e account (area riservata) lo richiedono: job in coda,
 *   email con link firmato, una richiesta ogni 24 ore;
 * - download: link firmato + autenticazione come titolare + nome del file
 *   che inizia con l'id del titolare (rotta del pacchetto non usata: guarda
 *   solo la guard di default);
 * - la segreteria lo genera e lo scarica subito per conto del socio.
 * Ogni richiesta finisce nel registro attività del titolare.
 */
class DatiPersonaliController extends Controller
{
    private const SECONDI_TRA_RICHIESTE = 86400;

    public static function urlDownload(Socio|Account $titolare, string $zipFilename, Carbon $scadenza): string
    {
        // Link costruito da APP_URL, mai dall'Host della richiesta
        $relativo = $titolare instanceof Socio
            ? URL::temporarySignedRoute('area-soci.dati-personali.scarica', $scadenza, ['file' => $zipFilename], false)
            : URL::temporarySignedRoute('pubblico.account.privacy.archivio.scarica', $scadenza, [
                'associazione' => $titolare->associazione()->withoutGlobalScopes()->value('slug'),
                'file' => $zipFilename,
            ], false);

        return rtrim((string) config('app.url'), '/').$relativo;
    }

    public function richiediSocio(Request $request): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        return $this->richiedi($socio, back());
    }

    public function scaricaSocio(Request $request, string $file): StreamedResponse
    {
        return $this->scarica($request->user('socio'), $file);
    }

    public function richiediAccount(Request $request, Associazione $associazione): RedirectResponse
    {
        /** @var Account $account */
        $account = $request->user('account');

        return $this->richiedi($account, back());
    }

    public function scaricaAccount(Request $request, Associazione $associazione, string $file): StreamedResponse
    {
        return $this->scarica($request->user('account'), $file);
    }

    /**
     * GET /soci/{socio}/dati-personali — la segreteria scarica subito l'archivio.
     */
    public function esportaPerSocio(Socio $socio, ArchivioDatiPersonali $archivio): StreamedResponse
    {
        Gate::authorize('esportaDatiPersonali', $socio);

        [$percorso, $cartella] = $archivio->creaOra($socio);

        activity()->performedOn($socio)->causedBy(auth()->user())
            ->event('dati_personali_esportati')
            ->log('Archivio dei dati personali generato dalla segreteria');

        return response()->streamDownload(function () use ($percorso, $cartella): void {
            readfile($percorso);
            $cartella->delete();
        }, 'dati-personali-'.str($socio->cognome.'-'.$socio->nome)->slug().'.zip', ['Content-Type' => 'application/zip']);
    }

    private function richiedi(Socio|Account $titolare, RedirectResponse $risposta): RedirectResponse
    {
        if (blank($titolare->email)) {
            return $risposta->with('error', 'Per ricevere i tuoi dati serve un indirizzo email nel profilo.');
        }

        $chiave = 'dati-personali:'.$titolare::class.':'.$titolare->getKey();
        if (RateLimiter::tooManyAttempts($chiave, 1)) {
            $ore = (int) ceil(RateLimiter::availableIn($chiave) / 3600);

            return $risposta->with('error', "Hai già richiesto i tuoi dati: puoi fare una nuova richiesta tra circa {$ore} ore.");
        }
        RateLimiter::hit($chiave, self::SECONDI_TRA_RICHIESTE);

        dispatch(new CreatePersonalDataExportJob($titolare));

        activity()->performedOn($titolare)->causedBy($titolare)
            ->event('dati_personali_richiesti')
            ->log('Richiesta dell\'archivio dei dati personali');

        return $risposta->with('success', 'Richiesta ricevuta: ti invieremo un\'email con il link per scaricare i tuoi dati.');
    }

    private function scarica(Socio|Account|null $titolare, string $file): StreamedResponse
    {
        abort_if($titolare === null, 403);
        abort_unless(preg_match(ArchivioDatiPersonali::FORMATO_NOME, $file) === 1, 404);
        abort_unless(str_starts_with($file, $titolare->getKey().'_'), 403);

        $disco = Storage::disk((string) config('personal-data-export.disk'));
        abort_unless($disco->exists($file), 404, 'L\'archivio è scaduto: puoi richiederne uno nuovo.');

        activity()->performedOn($titolare)->causedBy($titolare)
            ->event('dati_personali_scaricati')
            ->log('Archivio dei dati personali scaricato');

        return $disco->download($file, 'dati-personali.zip');
    }
}
