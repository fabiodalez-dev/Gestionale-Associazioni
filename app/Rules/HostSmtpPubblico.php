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

    public const MESSAGGIO = 'L\'host SMTP deve essere un server di posta pubblico.';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // ci pensano required|string
        }

        if (! self::ammesso($value)) {
            $fail(self::MESSAGGIO);
        }
    }

    /**
     * Vero se l'host (nome o IP) non è locale né privato, risolvendolo ORA.
     * Va richiamato anche subito prima di ogni connessione (TenantMailer):
     * la validazione al salvataggio non basta, perché un nome con TTL basso
     * può cambiare indirizzo dopo, e le impostazioni salvate prima della
     * regola non sono mai passate di qui.
     */
    public static function ammesso(string $valore): bool
    {
        return self::indirizziAmmessi($valore) !== null;
    }

    /**
     * Gli indirizzi IP pubblici a cui l'host risolve ORA, oppure null se
     * l'host è locale, privato, riservato o non risolvibile. Chi si connette
     * usa uno di questi IP (non il nome), così il DNS non può cambiare
     * indirizzo tra la validazione e la connessione. Lista vuota solo nei
     * test, dove la risoluzione è saltata.
     *
     * @return list<string>|null
     */
    public static function indirizziAmmessi(string $valore): ?array
    {
        $host = strtolower(trim($valore, "[] \t"));
        if ($host === '') {
            return null;
        }

        foreach (self::SUFFISSI_LOCALI as $suffisso) {
            if ($host === ltrim($suffisso, '.') || str_ends_with($host, $suffisso)) {
                return null;
            }
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $indirizzi = [$host];
        } else {
            if (app()->runningUnitTests()) {
                return [];
            }
            $indirizzi = self::risolvi($host);
            if ($indirizzi === []) {
                return null; // non risolvibile: nulla a cui connettersi in modo verificato
            }
        }

        foreach ($indirizzi as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return null;
            }
        }

        return $indirizzi;
    }

    /**
     * Risoluzione DNS (A e AAAA).
     *
     * @return list<string>
     */
    private static function risolvi(string $host): array
    {
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
