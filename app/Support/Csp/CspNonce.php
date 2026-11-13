<?php

namespace App\Support\Csp;

use Spatie\Csp\Keyword;

/**
 * Nonce CSP per gli script inline.
 *
 * Le view marcano ogni <script> inline con @cspNonce (direttiva di
 * spatie/laravel-csp); il generatore è sempre registrato (csp.nonce_enabled)
 * così la direttiva non fallisce mai. Se il nonce entra nella policy lo decide
 * csp.nonce_policy (CSP_NONCE_ENABLED): con false si torna al comportamento
 * precedente, cioè script-src con 'unsafe-inline' e nessun nonce, per le
 * installazioni che hanno personalizzato view con script o handler inline.
 */
class CspNonce
{
    public static function attivo(): bool
    {
        return (bool) config('csp.nonce_enabled', true)
            && (bool) config('csp.nonce_policy', true)
            && app()->bound('csp-nonce');
    }

    /**
     * Sorgente da aggiungere a script-src: il nonce della richiesta oppure
     * 'unsafe-inline' (mai entrambi: con un nonce il browser ignorerebbe
     * comunque 'unsafe-inline').
     */
    public static function sorgenteScript(): string
    {
        if (self::attivo()) {
            $nonce = (string) app('csp-nonce');
            if ($nonce !== '') {
                return "'nonce-{$nonce}'";
            }
        }

        return "'".Keyword::UNSAFE_INLINE->value."'";
    }

    /**
     * Valore completo di script-src per le CSP scritte a mano
     * (pagine scanner/check-in che non passano da AddCspHeaders).
     */
    public static function scriptSrc(): string
    {
        return "'self' ".self::sorgenteScript();
    }
}
