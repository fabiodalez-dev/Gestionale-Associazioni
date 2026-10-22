<?php

namespace App\Services\Calendario;

use App\Settings\GoogleCalendarSettings;
use Carbon\CarbonInterface;
use Google\Service\Calendar as CalendarService;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;
use Google\Service\Exception as GoogleException;
use RuntimeException;
use Spatie\GoogleCalendar\GoogleCalendar;
use Spatie\GoogleCalendar\GoogleCalendarFactory;

/**
 * Google Calendar tramite spatie/laravel-google-calendar, con le credenziali
 * dell'associazione passate per ogni chiamata (la configurazione globale del
 * pacchetto non viene toccata, quindi un job non vede le credenziali di
 * un'altra associazione).
 */
class GoogleCalendarSpatie implements CalendarioEsterno
{
    public function salva(GoogleCalendarSettings $impostazioni, array $dati, ?string $idEsterno): string
    {
        $calendario = $this->calendario($impostazioni);

        $evento = new GoogleEvent;
        $evento->setSummary($dati['titolo']);
        $evento->setDescription($dati['descrizione']);
        $evento->setLocation((string) $dati['luogo']);
        $evento->setStart($this->orario($dati['inizio']));
        $evento->setEnd($this->orario($dati['fine']));

        if (filled($idEsterno)) {
            try {
                return (string) $calendario->getService()->events
                    ->update($calendario->getCalendarId(), (string) $idEsterno, $evento)->getId();
            } catch (GoogleException $e) {
                // Evento rimosso a mano dal calendario: si ricrea
                if (! in_array($e->getCode(), [404, 410], true)) {
                    throw $e;
                }
            }
        }

        return (string) $calendario->insertEvent($evento)->getId();
    }

    public function elimina(GoogleCalendarSettings $impostazioni, string $idEsterno): void
    {
        try {
            $this->calendario($impostazioni)->deleteEvent($idEsterno);
        } catch (GoogleException $e) {
            if (! in_array($e->getCode(), [404, 410], true)) {
                throw $e;
            }
        }
    }

    public function verifica(GoogleCalendarSettings $impostazioni): string
    {
        $calendario = $this->calendario($impostazioni);

        return (string) $calendario->getService()->calendars->get($calendario->getCalendarId())->getSummary();
    }

    private function calendario(GoogleCalendarSettings $impostazioni): GoogleCalendar
    {
        $credenziali = $impostazioni->credenziali();
        if ($credenziali === null || blank($impostazioni->calendar_id)) {
            throw new RuntimeException('Credenziali Google Calendar mancanti o non leggibili: caricale di nuovo.');
        }

        $client = GoogleCalendarFactory::createAuthenticatedGoogleClient([
            'default_auth_profile' => 'service_account',
            'auth_profiles' => ['service_account' => ['credentials_json' => $credenziali]],
        ]);

        return new GoogleCalendar(new CalendarService($client), (string) $impostazioni->calendar_id);
    }

    private function orario(CarbonInterface $quando): EventDateTime
    {
        $orario = new EventDateTime;
        $orario->setDateTime($quando->toRfc3339String());
        $orario->setTimeZone((string) config('app.timezone'));

        return $orario;
    }
}
