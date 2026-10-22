<?php

namespace App\Support;

/**
 * Protezione dalla "formula injection" negli export CSV/Excel: un testo
 * scritto da un utente (es. un nome dal modulo pubblico) che inizia con
 * = + - @ tab o a capo verrebbe eseguito come formula aprendo il file.
 * Si antepone un apostrofo, come raccomandato da OWASP.
 */
final class CellaSicura
{
    private const INIZI_PERICOLOSI = ['=', '+', '-', '@', "\t", "\r"];

    public static function valore(mixed $valore): mixed
    {
        if (! is_string($valore) || $valore === '') {
            return $valore;
        }

        // Numeri con segno (importi "-25,00") e telefoni ("+39 333 123 4567") non sono formule
        if (preg_match('/^[+-]?\d+([.,]\d+)?$/', $valore) === 1 || preg_match('/^\+[\d\s().\/-]+$/', $valore) === 1) {
            return $valore;
        }

        return in_array($valore[0], self::INIZI_PERICOLOSI, true) ? "'".$valore : $valore;
    }

    /**
     * @param  array<int, mixed>  $riga
     * @return array<int, mixed>
     */
    public static function riga(array $riga): array
    {
        return array_map(self::valore(...), $riga);
    }
}
