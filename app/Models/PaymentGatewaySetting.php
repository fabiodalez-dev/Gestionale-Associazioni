<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentGatewaySetting extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const PAYPAL_MODES = ['sandbox', 'live'];

    protected $table = 'payment_gateway_settings';

    protected $fillable = [
        'associazione_id',
        'stripe_enabled',
        'stripe_publishable_key',
        'stripe_secret_key_encrypted',
        'stripe_webhook_secret_encrypted',
        'paypal_enabled',
        'paypal_client_id',
        'paypal_client_secret_encrypted',
        'paypal_mode',
        'paypal_webhook_id',
        'auto_attivazione_pagamento',
        'valuta',
    ];

    protected $hidden = [
        'stripe_secret_key_encrypted',
        'stripe_webhook_secret_encrypted',
        'paypal_client_secret_encrypted',
    ];

    /** @var list<string> attributi esclusi dall'activity log */
    protected array $activityLogExcept = [
        'stripe_secret_key_encrypted',
        'stripe_webhook_secret_encrypted',
        'paypal_client_secret_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'stripe_enabled' => 'boolean',
            // Cifratura applicativa gestita da Laravel (sostituisce l'AES-256-CBC custom del legacy)
            'stripe_secret_key_encrypted' => 'encrypted',
            'stripe_webhook_secret_encrypted' => 'encrypted',
            'paypal_enabled' => 'boolean',
            'paypal_client_secret_encrypted' => 'encrypted',
            'auto_attivazione_pagamento' => 'boolean',
        ];
    }

    /**
     * Segreto Stripe decifrato. Parità legacy: se la decifratura fallisce
     * il valore diventa stringa vuota (gateway di fatto disabilitato, nessun errore esposto).
     */
    public function getStripeSecretKeyAttribute(): string
    {
        return $this->decryptedOrEmpty('stripe_secret_key_encrypted');
    }

    public function getStripeWebhookSecretAttribute(): string
    {
        return $this->decryptedOrEmpty('stripe_webhook_secret_encrypted');
    }

    public function getPaypalClientSecretAttribute(): string
    {
        return $this->decryptedOrEmpty('paypal_client_secret_encrypted');
    }

    protected function decryptedOrEmpty(string $attribute): string
    {
        try {
            return (string) ($this->getAttributeValue($attribute) ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Check forte (parità con PaymentService::isStripeEnabled legacy):
     * abilitato + publishable key + secret key.
     */
    public function isStripeOperational(): bool
    {
        return $this->stripe_enabled
            && filled($this->stripe_publishable_key)
            && filled($this->stripe_secret_key);
    }

    /**
     * Check forte: abilitato + client_id + client_secret + webhook_id.
     * Il webhook id è richiesto perché senza non si può verificare la firma
     * dei webhook e l'incasso non potrebbe mai essere confermato in sicurezza.
     */
    public function isPayPalOperational(): bool
    {
        return $this->paypal_enabled
            && filled($this->paypal_client_id)
            && filled($this->paypal_client_secret)
            && filled($this->paypal_webhook_id);
    }

    /**
     * Modalità attesa degli eventi Stripe (true = live, false = test),
     * ricavata dal prefisso della chiave segreta (sk_live_/rk_live_ oppure
     * sk_test_/rk_test_); null se il prefisso non è riconoscibile.
     */
    public function stripeLivemodeAttesa(): ?bool
    {
        $chiave = $this->stripe_secret_key;

        if (preg_match('/^(sk|rk)_live_/', $chiave) === 1) {
            return true;
        }

        if (preg_match('/^(sk|rk)_test_/', $chiave) === 1) {
            return false;
        }

        return null;
    }

    public function hasOperationalGateway(): bool
    {
        return $this->isStripeOperational() || $this->isPayPalOperational();
    }

    /**
     * Config per il tenant indicato, ignorando lo scope di sessione
     * (usato da webhook e pagina pubblica, dove il tenant deriva dalla quota).
     */
    public static function forTenant(?string $associazioneId): ?self
    {
        if (blank($associazioneId)) {
            return null;
        }

        return static::allTenants()->where('associazione_id', $associazioneId)->first();
    }
}
