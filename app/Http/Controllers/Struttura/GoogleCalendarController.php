<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\GoogleCalendarRequest;
use App\Jobs\SincronizzaEventoGoogleCalendar;
use App\Models\Evento;
use App\Services\Calendario\CalendarioEsterno;
use App\Settings\GoogleCalendarSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Configurazione della sincronizzazione con Google Calendar
 * (sezione "Google Calendar" delle configurazioni, admin dell'associazione).
 */
class GoogleCalendarController extends Controller
{
    public function update(GoogleCalendarRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();
        $impostazioni = $associazione->impostazioni(GoogleCalendarSettings::class);
        $dati = [
            'attiva' => $request->boolean('attiva'),
            'calendar_id' => trim((string) $request->validated('calendar_id')),
        ];

        $json = $request->hasFile('credenziali_file')
            ? (string) $request->file('credenziali_file')->get()
            : trim((string) $request->validated('credenziali_json'));

        if ($json !== '') {
            try {
                [$dati['credenziali_cifrate'], $dati['service_account_email']] = GoogleCalendarSettings::cifraCredenziali($json);
            } catch (InvalidArgumentException $e) {
                return back()->withInput($request->except(['credenziali_json']))->withErrors(['credenziali_json' => $e->getMessage()]);
            }
        } elseif ($request->boolean('rimuovi_credenziali')) {
            $dati = ['attiva' => false, 'credenziali_cifrate' => null, 'service_account_email' => null] + $dati;
        }

        $impostazioni->aggiorna($dati);

        if ($impostazioni->attiva && ! $impostazioni->configurata()) {
            $impostazioni->aggiorna(['attiva' => false]);

            return $this->torna('Impostazioni salvate, ma la sincronizzazione resta spenta: mancano le credenziali del service account.', 'warning');
        }

        return $this->torna('Impostazioni di Google Calendar salvate.');
    }

    public function verifica(GoogleCalendarRequest $request, CalendarioEsterno $calendario): RedirectResponse
    {
        $impostazioni = $request->associazioneCorrente()->impostazioni(GoogleCalendarSettings::class);

        if (! $impostazioni->configurata()) {
            return $this->torna('Salva prima ID del calendario e credenziali.', 'error');
        }

        try {
            $nome = $calendario->verifica($impostazioni);
        } catch (Throwable $e) {
            return $this->torna('Connessione non riuscita: '.Str::limit($e->getMessage(), 300), 'error');
        }

        return $this->torna("Connessione riuscita al calendario «{$nome}».");
    }

    /**
     * Accoda la sincronizzazione degli eventi pubblicati futuri (prima
     * attivazione o dopo un cambio di calendario).
     */
    public function sincronizzaTutti(GoogleCalendarRequest $request): RedirectResponse
    {
        $associazione = $request->associazioneCorrente();
        if (! $associazione->impostazioni(GoogleCalendarSettings::class)->attivaEConfigurata()) {
            return $this->torna('Attiva e configura prima la sincronizzazione.', 'error');
        }

        $numero = 0;
        Evento::query()->withoutGlobalScopes()
            ->where('associazione_id', $associazione->id)
            ->where('stato_pubblicazione', Evento::STATO_PUBBLICATO)
            ->where('data_evento', '>=', now()->startOfDay())
            ->select('id')
            ->lazyById()
            ->each(function (Evento $evento) use (&$numero): void {
                SincronizzaEventoGoogleCalendar::dispatch((string) $evento->id);
                $numero++;
            });

        return $this->torna("Sincronizzazione accodata per {$numero} eventi futuri.");
    }

    private function torna(string $messaggio, string $tipo = 'success'): RedirectResponse
    {
        return redirect()->route('configurazioni.index', ['section' => 'google-calendar'])->with($tipo, $messaggio);
    }
}
