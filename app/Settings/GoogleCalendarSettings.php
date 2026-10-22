<?php

namespace App\Settings;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Sincronizzazione degli eventi con un Google Calendar dell'associazione
 * (service account con accesso in scrittura al calendario). Credenziali
 * JSON cifrate con la APP_KEY; mai rimostrate nell'interfaccia.
 */
class GoogleCalendarSettings extends ImpostazioniAssociazione
{
    public bool $attiva;

    public ?string $calendar_id;

    /** JSON del service account, cifrato con Crypt::encryptString. */
    public ?string $credenziali_cifrate;

    /** Email del service account (da condividere sul calendario), per l'interfaccia. */
    public ?string $service_account_email;

    public ?string $ultimo_errore;

    public ?string $ultimo_errore_at;

    public static function group(): string
    {
        return 'google_calendar';
    }

    /** Nessuna colonna corrispondente su `associazioni`. */
    public static function colonne(): array
    {
        return [];
    }

    public function configurata(): bool
    {
        return filled($this->calendar_id) && filled($this->credenziali_cifrate);
    }

    public function attivaEConfigurata(): bool
    {
        return $this->attiva && $this->configurata();
    }

    /**
     * Credenziali decifrate, null se mancanti o non leggibili (APP_KEY cambiata).
     *
     * @return array<string, mixed>|null
     */
    public function credenziali(): ?array
    {
        if (blank($this->credenziali_cifrate)) {
            return null;
        }

        try {
            $json = json_decode(Crypt::decryptString((string) $this->credenziali_cifrate), true);
        } catch (DecryptException) {
            return null;
        }

        return is_array($json) ? $json : null;
    }

    /**
     * Valida e cifra il JSON di un service account.
     *
     * @return array{0: string, 1: string} [credenziali cifrate, email del service account]
     *
     * @throws \InvalidArgumentException
     */
    public static function cifraCredenziali(string $json): array
    {
        $dati = json_decode($json, true);

        if (! is_array($dati) || ($dati['type'] ?? null) !== 'service_account'
            || blank($dati['client_email'] ?? null) || blank($dati['private_key'] ?? null)) {
            throw new \InvalidArgumentException('Il file non è la chiave JSON di un service account Google.');
        }

        return [Crypt::encryptString(json_encode($dati, JSON_UNESCAPED_SLASHES) ?: ''), (string) $dati['client_email']];
    }

    protected static function normalizza(array $valori): array
    {
        $testo = fn (string $k) => filled($valori[$k] ?? null) ? (string) $valori[$k] : null;

        return [
            'attiva' => filter_var($valori['attiva'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'calendar_id' => $testo('calendar_id'),
            'credenziali_cifrate' => $testo('credenziali_cifrate'),
            'service_account_email' => $testo('service_account_email'),
            'ultimo_errore' => $testo('ultimo_errore'),
            'ultimo_errore_at' => $testo('ultimo_errore_at'),
        ];
    }
}
