<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * L'host SMTP configurato da un amministratore di associazione deve essere
 * un server pubblico: il test di invio apre una connessione TCP DAL server
 * dell'applicazione e riporta l'errore, quindi loopback, rete privata e
 * link-local trasformerebbero il modulo in una sonda della rete interna
 * dell'hosting.
 */
class HostSmtpPubblico implements DataAwareRule, ValidationRule
{
    private const SUFFISSI_LOCALI = ['localhost', '.localhost', '.local', '.internal', '.lan', '.home.arpa', '.intranet'];

    /** Porte ammesse per un relay privato dichiarato senza porta esplicita. */
    private const PORTE_POSTA = [25, 465, 587, 2525];

    public const MESSAGGIO = 'L\'host SMTP deve essere un server di posta pubblico.';

    /** @var array<string, mixed> */
    private array $dati = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->dati = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || $value === '') {
            return; // ci pensano required|string
        }

        $porta = $this->dati['smtp_port'] ?? null;

        if (! self::ammesso($value, is_numeric($porta) ? (int) $porta : null)) {
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
    public static function ammesso(string $valore, ?int $porta = null): bool
    {
        return self::indirizziAmmessi($valore, $porta) !== null;
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
    public static function indirizziAmmessi(string $valore, ?int $porta = null): ?array
    {
        $host = strtolower(trim($valore, "[] \t"));
        if ($host === '') {
            return null;
        }

        // Relay interni decisi dall'operatore della piattaforma (non dal
        // tenant): es. il postfix locale di un hosting condiviso. Niente
        // risoluzione né pinning: il nome resta com'è. La porta fa parte
        // dell'allowlist, altrimenti l'host locale aprirebbe ogni porta.
        if (self::relayPrivatoAmmesso($host, $porta)) {
            return [];
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
     * Vero se host (e porta, quando nota) corrispondono a una voce
     * dell'allowlist dell'operatore: config mail.smtp_tenant_host_privati
     * (env SMTP_TENANT_HOST_PRIVATI), voci separate da virgola nella forma
     * `host` (solo porte di posta: 25, 465, 587, 2525) oppure `host:porta`.
     */
    public static function relayPrivatoAmmesso(string $host, ?int $porta): bool
    {
        foreach (self::vociAllowlist() as [$hostAmmesso, $portaAmmessa]) {
            if ($hostAmmesso !== $host) {
                continue;
            }
            if ($porta === null) {
                return true; // solo host: la porta la valuta chi conosce la porta
            }
            if ($portaAmmessa === null ? in_array($porta, self::PORTE_POSTA, true) : $portaAmmessa === $porta) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{0: string, 1: int|null}>
     */
    private static function vociAllowlist(): array
    {
        $valore = config('mail.smtp_tenant_host_privati', '');
        $lista = is_array($valore) ? $valore : explode(',', (string) $valore);
        $voci = [];

        foreach ($lista as $voce) {
            $voce = strtolower(trim((string) $voce));
            if ($voce === '') {
                continue;
            }
            // [ipv6]:porta | host:porta | host | [ipv6]
            if (preg_match('/^\[(.+)\](?::(\d+))?$/', $voce, $m)) {
                $voci[] = [$m[1], isset($m[2]) ? (int) $m[2] : null];
            } elseif (preg_match('/^([^:]+):(\d+)$/', $voce, $m)) {
                $voci[] = [$m[1], (int) $m[2]];
            } else {
                $voci[] = [$voce, null];
            }
        }

        return $voci;
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
