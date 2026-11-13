<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Nome host (etichette alfanumeriche, trattini e underscore, separate da
 * punti) oppure indirizzo IPv4/IPv6. Non esclude localhost né le reti
 * private: nell'installer il database è legittimamente locale. Serve a
 * rifiutare valori che non sono un host (spazi, slash, caratteri di
 * controllo) prima che finiscano in un DSN o nel .env.
 */
class HostnameOIp implements ValidationRule
{
    public static function valido(string $host): bool
    {
        if ($host === '' || strlen($host) > 253) {
            return false;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Etichette fino a 63 caratteri; l'underscore non è RFC ma è comune
        // nei nomi dei container (es. db_1)
        return preg_match('/^(?:[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?)(?:\.(?:[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?))*\.?$/', $host) === 1;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::valido($value)) {
            $fail('Il campo :attribute deve essere un nome host o un indirizzo IP valido.');
        }
    }
}
