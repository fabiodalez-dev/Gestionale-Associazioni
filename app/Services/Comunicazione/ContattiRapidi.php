<?php

namespace App\Services\Comunicazione;

/**
 * Contatti rapidi (f.10 piano Gestione 2): costruzione dei link
 * click-to-chat/chiama — pura utilità, zero API esterne.
 *
 * wa.me richiede il numero in formato internazionale SENZA '+' né zeri
 * iniziali: i numeri italiani senza prefisso vengono prefissati con 39.
 */
class ContattiRapidi
{
    /** Prefisso internazionale di default (Italia) per i numeri "nudi". */
    public const PREFISSO_DEFAULT = '39';

    /**
     * URL wa.me del numero, o NULL se il telefono non è utilizzabile.
     * Chiamato dalla vista comunicazione/soci/_contatti-rapidi.
     *
     * @api
     */
    public static function whatsappUrl(?string $telefono): ?string
    {
        $numero = self::numeroInternazionale($telefono);

        return $numero !== null ? 'https://wa.me/'.$numero : null;
    }

    /**
     * URL tel: del numero (così com'è, spazi esclusi), o NULL.
     * Chiamato dalla vista comunicazione/soci/_contatti-rapidi.
     *
     * @api
     */
    public static function telUrl(?string $telefono): ?string
    {
        $pulito = preg_replace('/[^\d+]/', '', (string) $telefono) ?? '';

        return $pulito !== '' && $pulito !== '+' ? 'tel:'.$pulito : null;
    }

    /**
     * Numero in formato internazionale "solo cifre" per wa.me:
     * - "+39 333 1234567" → 393331234567
     * - "0039 333 1234567" → 393331234567
     * - "333 1234567" → 393331234567 (prefisso default)
     */
    public static function numeroInternazionale(?string $telefono): ?string
    {
        $telefono = trim((string) $telefono);

        if ($telefono === '') {
            return null;
        }

        $haPiu = str_starts_with($telefono, '+');
        $cifre = preg_replace('/\D/', '', $telefono) ?? '';

        if (strlen($cifre) < 6) {
            return null; // troppo corto per essere un numero reale
        }

        if ($haPiu) {
            return $cifre;
        }

        if (str_starts_with($cifre, '00')) {
            $senzaZeri = ltrim($cifre, '0');

            return $senzaZeri !== '' ? $senzaZeri : null;
        }

        return self::PREFISSO_DEFAULT.$cifre;
    }
}
