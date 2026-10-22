<?php

namespace App\Services\VolontariTurni;

/**
 * Vocabolario e normalizzazione della disponibilità volontariato del socio
 * (f.1 del piano "Organizzazione 3"). La disponibilità è memorizzata su
 * soci.disponibilita come json {giorno: [fascia, ...], ...}, p.es.
 * {"lun": ["mattina","sera"], "sab": ["pomeriggio"]}.
 *
 * Tenuto fuori dal Model condiviso Socio (regola: i moduli non toccano i
 * Model condivisi): è un value-object di supporto del modulo.
 */
final class Disponibilita
{
    /** @var array<string, string> codice giorno => etichetta */
    public const GIORNI = [
        'lun' => 'Lunedì',
        'mar' => 'Martedì',
        'mer' => 'Mercoledì',
        'gio' => 'Giovedì',
        'ven' => 'Venerdì',
        'sab' => 'Sabato',
        'dom' => 'Domenica',
    ];

    /** @var array<string, string> codice fascia => etichetta */
    public const FASCE = [
        'mattina' => 'Mattina',
        'pomeriggio' => 'Pomeriggio',
        'sera' => 'Sera',
    ];

    /**
     * Normalizza l'input grezzo del form ({giorno: [fascia, ...]}) tenendo
     * solo giorni/fasce validi e scartando i giorni senza fasce.
     *
     * @param  mixed  $raw
     * @return array<string, list<string>>
     */
    public static function normalizza($raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];

        foreach (array_keys(self::GIORNI) as $giorno) {
            $fasce = $raw[$giorno] ?? null;

            if (! is_array($fasce)) {
                continue;
            }

            $valide = array_values(array_intersect(
                array_keys(self::FASCE),
                array_map('strval', $fasce)
            ));

            if ($valide !== []) {
                $out[$giorno] = $valide;
            }
        }

        return $out;
    }

    /**
     * Verifica se la disponibilità copre la data/fascia di un turno
     * (suggerimenti per disponibilità, f.3).
     *
     * @param  array<string, list<string>>|null  $disponibilita
     */
    public static function copre(?array $disponibilita, string $giorno, string $fascia): bool
    {
        if (empty($disponibilita) || ! isset($disponibilita[$giorno])) {
            return false;
        }

        return in_array($fascia, (array) $disponibilita[$giorno], true);
    }

    /**
     * Codice giorno (lun..dom) da un numero ISO (1=lunedì ... 7=domenica).
     */
    public static function giornoDaIso(int $iso): string
    {
        $codici = array_keys(self::GIORNI);

        return $codici[($iso - 1) % 7] ?? 'lun';
    }

    /**
     * Fascia (mattina/pomeriggio/sera) dall'ora di inizio del turno.
     */
    public static function fasciaDaOra(string $ora): string
    {
        $h = (int) substr($ora, 0, 2);

        return match (true) {
            $h < 13 => 'mattina',
            $h < 18 => 'pomeriggio',
            default => 'sera',
        };
    }
}
