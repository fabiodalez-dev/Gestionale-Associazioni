<?php

namespace App\Services\EventiVita;

use App\Models\Associazione;
use App\Models\Evento;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Export iCalendar (RFC 5545) degli eventi (f.7 del piano Vita sociale):
 * .ics del singolo evento e feed calendario.ics dei soli pubblicati.
 *
 * Date in UTC (suffisso Z), testo con escaping RFC (\\ \; \, \n) e line
 * folding a 75 ottetti: compatibile con Google Calendar / Apple Calendar
 * / Outlook senza dipendenze esterne.
 */
class IcsService
{
    /**
     * Contenuto .ics di un singolo evento.
     */
    public function evento(Evento $evento, ?Associazione $associazione = null): string
    {
        return $this->calendario(collect([$evento]), $associazione);
    }

    /**
     * Feed .ics di una collezione di eventi (es. i pubblicati del tenant).
     *
     * @param  Collection<int, Evento>  $eventi
     */
    public function calendario(Collection $eventi, ?Associazione $associazione = null): string
    {
        $righe = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Gestione Associazioni//Eventi//IT',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
        ];

        if ($associazione !== null) {
            $righe[] = 'X-WR-CALNAME:'.$this->escape('Eventi '.$associazione->nome);
        }

        foreach ($eventi as $evento) {
            $righe = array_merge($righe, $this->vevent($evento, $associazione));
        }

        $righe[] = 'END:VCALENDAR';

        return implode("\r\n", array_map($this->fold(...), $righe))."\r\n";
    }

    /**
     * Nome file suggerito per il download del singolo evento.
     */
    public function filename(Evento $evento): string
    {
        $slug = str($evento->titolo ?: 'evento')->slug()->limit(60, '');

        return ($slug->isEmpty() ? 'evento' : (string) $slug).'.ics';
    }

    /**
     * @return list<string>
     */
    protected function vevent(Evento $evento, ?Associazione $associazione): array
    {
        $righe = [
            'BEGIN:VEVENT',
            'UID:'.$evento->id.'@'.parse_url((string) config('app.url'), PHP_URL_HOST),
            'DTSTAMP:'.$this->utc($evento->updated_at ?? now()),
            'DTSTART:'.$this->utc($evento->data_evento),
        ];

        if ($evento->data_fine !== null) {
            $righe[] = 'DTEND:'.$this->utc($evento->data_fine);
        }

        $righe[] = 'SUMMARY:'.$this->escape((string) $evento->titolo);

        if (filled($evento->descrizione)) {
            $righe[] = 'DESCRIPTION:'.$this->escape((string) $evento->descrizione);
        }

        if (filled($evento->luogo)) {
            $righe[] = 'LOCATION:'.$this->escape((string) $evento->luogo);
        }

        if ($associazione !== null && filled($associazione->slug)) {
            $righe[] = 'URL:'.route('pubblico.eventi.show', [
                'associazione' => $associazione->slug,
                'evento' => $evento->id,
            ]);
        }

        $righe[] = 'END:VEVENT';

        return $righe;
    }

    /**
     * Data/ora in UTC nel formato iCalendar (es. 20260612T180000Z).
     */
    protected function utc(\DateTimeInterface $data): string
    {
        return Carbon::instance(Carbon::parse($data))
            ->utc()
            ->format('Ymd\THis\Z');
    }

    /**
     * Escaping del testo per le proprietà iCalendar (RFC 5545 §3.3.11).
     */
    protected function escape(string $testo): string
    {
        $testo = str_replace(["\r\n", "\r"], "\n", $testo);

        return str_replace(
            ['\\', ';', ',', "\n"],
            ['\\\\', '\;', '\,', '\n'],
            $testo
        );
    }

    /**
     * Line folding: le righe oltre i 75 ottetti continuano su una nuova
     * riga preceduta da uno spazio (RFC 5545 §3.1), senza spezzare i
     * caratteri multibyte.
     */
    protected function fold(string $riga): string
    {
        if (strlen($riga) <= 75) {
            return $riga;
        }

        $pezzi = [];
        $corrente = '';

        foreach (mb_str_split($riga) as $carattere) {
            // 74 per lasciare spazio allo spazio iniziale delle continuazioni
            if (strlen($corrente) + strlen($carattere) > 74) {
                $pezzi[] = $corrente;
                $corrente = '';
            }

            $corrente .= $carattere;
        }

        // L'ultimo pezzo è sempre non vuoto: ogni flush è seguito da un append
        $pezzi[] = $corrente;

        return implode("\r\n ", $pezzi);
    }
}
