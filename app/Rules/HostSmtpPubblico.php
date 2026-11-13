<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * L'host SMTP configurato da un amministratore di associazione deve essere
 * un server pubblico: il test di invio apre una connessione TCP DAL server
 * dell'applicazione e riporta l'errore, quindi loopback, rete privata e
 * link-local trasformerebbero il modulo in una sonda della rete interna
 * dell'hosting.
 */
class HostSmtpPubblico implements ValidationRule
{
    private const SUFFISSI_LOCALI = ['localhost', '.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // ci pensano required|string
        }

        $host = strtolower(trim($value, "[] \t"));

        foreach (self::SUFFISSI_LOCALI as $suffisso) {
            if ($host === ltrim($suffisso, '.') || str_ends_with($host, $suffisso)) {
                $fail('L\'host SMTP deve essere un server di posta pubblico.');

                return;
            }
        }

        $indirizzi = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : $this->risolvi($host);

        foreach ($indirizzi as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                $fail('L\'host SMTP deve essere un server di posta pubblico.');

                return;
            }
        }
    }

    /**
     * Risoluzione DNS best-effort: se il nome non si risolve non c'è nulla da
     * sondare (la connessione fallirebbe comunque). Nei test niente DNS.
     *
     * @return list<string>
     */
    private function risolvi(string $host): array
    {
        if (app()->runningUnitTests()) {
            return [];
        }

        $ipv4 = gethostbynamel($host) ?: [];
        $ipv6 = [];
        foreach (dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (isset($record['ipv6'])) {
                $ipv6[] = $record['ipv6'];
            }
        }

        return array_values(array_unique([...$ipv4, ...$ipv6]));
    }
}
