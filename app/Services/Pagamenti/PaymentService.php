<?php

namespace App\Services\Pagamenti;

use App\Models\Associazione;
use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Notifications\Quote\BenvenutoSocioNotification;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestratore pagamenti quote (parità con includes/PaymentService.php legacy):
 * token/link di pagamento, checkout Stripe/PayPal, webhook, post-pagamento
 * (auto-attivazione socio + tessera + notifiche), helper createQuotaForSocio.
 */
class PaymentService
{
    public const TOKEN_EXPIRY_HOURS = 72;

    public const GIORNI_PREAVVISO_SCADENZA = 30;

    public function __construct(
        protected StripeGatewayInterface $stripe,
        protected PayPalGatewayInterface $paypal,
    ) {}

    // ------------------------------------------------------------------
    // Token / link pubblico
    // ------------------------------------------------------------------

    /**
     * Genera (sovrascrivendo l'eventuale precedente) il token monouso
     * per il link di pagamento pubblico: 64 char esadecimali, scadenza 72h.
     */
    public function generatePaymentToken(Quota $quota, int $expiryHours = self::TOKEN_EXPIRY_HOURS): string
    {
        $token = bin2hex(random_bytes(32));

        $quota->forceFill([
            'payment_token' => $token,
            'payment_token_expires' => now()->addHours($expiryHours),
        ])->save();

        return $token;
    }

    public function paymentUrl(Quota $quota): ?string
    {
        if (blank($quota->payment_token)) {
            return null;
        }

        return route('pagamento.show', ['token' => $quota->payment_token]);
    }

    // ------------------------------------------------------------------
    // Pagamento manuale (offline)
    // ------------------------------------------------------------------

    /**
     * Registra un pagamento offline (contanti, bonifico, carta, altro).
     * Parità legacy: aggiorna solo data_pagamento + metodo_pagamento,
     * logga l'attività socio e notifica best-effort.
     */
    public function registerOfflinePayment(Quota $quota, string $metodo, string $dataPagamento, ?Utente $utente = null): void
    {
        $quota->forceFill([
            'data_pagamento' => $dataPagamento,
            'metodo_pagamento' => $metodo,
        ])->save();

        $socio = $quota->socio()->withTrashed()->first();

        if ($socio !== null) {
            $this->logSocioActivity(
                $socio,
                'Pagamento Quota',
                sprintf('Pagata quota di €%s per l\'anno %d (%s).', number_format((float) $quota->importo, 2, ',', '.'), $quota->anno, $metodo),
                $utente
            );

            $this->notifyBestEffort($socio, new PagamentoQuotaNotification($quota->fresh() ?? $quota));
        }
    }

    // ------------------------------------------------------------------
    // Checkout
    // ------------------------------------------------------------------

    public function createStripeCheckout(Quota $quota, string $returnUrl, string $cancelUrl): ?string
    {
        $config = PaymentGatewaySetting::forTenant($quota->associazione_id);

        if ($config === null || ! $config->isStripeOperational()) {
            return null;
        }

        return $this->stripe->createCheckoutSession($config, $quota, $returnUrl, $cancelUrl);
    }

    public function createPayPalOrder(Quota $quota, string $returnUrl, string $cancelUrl): ?string
    {
        $config = PaymentGatewaySetting::forTenant($quota->associazione_id);

        if ($config === null || ! $config->isPayPalOperational()) {
            return null;
        }

        return $this->paypal->createOrder($config, $quota, $returnUrl, $cancelUrl);
    }

    // ------------------------------------------------------------------
    // Webhook
    // ------------------------------------------------------------------

    /**
     * Gestisce il webhook Stripe per il tenant indicato.
     * Verifica firma obbligatoria con il webhook secret per-tenant.
     */
    public function handleStripeWebhook(string $payload, string $signatureHeader, string $associazioneId): bool
    {
        $config = PaymentGatewaySetting::forTenant($associazioneId);

        if ($config === null || blank($config->stripe_webhook_secret)) {
            return false;
        }

        $event = $this->stripe->constructWebhookEvent($payload, $signatureHeader, $config->stripe_webhook_secret);

        if ($event === null) {
            return false;
        }

        if (($event['type'] ?? null) !== 'checkout.session.completed') {
            // Eventi diversi: ack senza azione (evita retry inutili da Stripe)
            return true;
        }

        $session = (array) data_get($event, 'data.object', []);
        $quotaId = data_get($session, 'metadata.quota_id');

        if (blank($quotaId)) {
            Log::warning('PaymentService: webhook Stripe senza quota_id nei metadata');

            return false;
        }

        $transactionId = (string) (data_get($session, 'payment_intent') ?: data_get($session, 'id', ''));

        return $this->onPaymentCompleted((string) $quotaId, $associazioneId, 'stripe', $transactionId);
    }

    /**
     * Gestisce il webhook PayPal per il tenant indicato:
     * verifica firma (se paypal_webhook_id configurato) + CAPTURE dell'ordine approvato.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, string|null>  $headers
     */
    public function handlePayPalWebhook(array $event, array $headers, string $associazioneId): bool
    {
        $config = PaymentGatewaySetting::forTenant($associazioneId);

        if ($config === null || ! $config->isPayPalOperational()) {
            return false;
        }

        // Fix sicurezza rispetto al legacy: verifica firma ufficiale
        if (! $this->paypal->verifyWebhookSignature($config, $headers, $event)) {
            Log::warning('PaymentService: firma webhook PayPal non valida', ['associazione_id' => $associazioneId]);

            return false;
        }

        if (($event['event_type'] ?? null) !== 'CHECKOUT.ORDER.APPROVED') {
            // Altri eventi: ack senza azione
            return true;
        }

        $orderId = data_get($event, 'resource.id');

        if (blank($orderId)) {
            return false;
        }

        // È il webhook a completare l'incasso (capture server-side)
        $capture = $this->paypal->captureOrder($config, (string) $orderId);

        if ($capture === null || ($capture['status'] ?? null) !== 'COMPLETED') {
            Log::warning('PaymentService: capture PayPal non completato', ['order_id' => $orderId]);

            return false;
        }

        $quotaId = data_get($capture, 'purchase_units.0.payments.captures.0.custom_id')
            ?: data_get($capture, 'purchase_units.0.custom_id')
            ?: data_get($event, 'resource.purchase_units.0.custom_id');

        if (blank($quotaId)) {
            return false;
        }

        $captureId = data_get($capture, 'purchase_units.0.payments.captures.0.id');
        $transactionId = (string) ($captureId ?: $orderId);

        return $this->onPaymentCompleted((string) $quotaId, $associazioneId, 'paypal', $transactionId);
    }

    // ------------------------------------------------------------------
    // Post-pagamento comune
    // ------------------------------------------------------------------

    /**
     * Aggiorna la quota come pagata, logga, auto-attiva il socio (+ tessera)
     * e invia le notifiche. Idempotente: un webhook duplicato viene ack-ato
     * senza ripetere effetti collaterali.
     */
    public function onPaymentCompleted(string $quotaId, string $associazioneId, string $gateway, string $transactionId): bool
    {
        $notifiche = [];

        $ok = DB::transaction(function () use ($quotaId, $associazioneId, $gateway, $transactionId, &$notifiche): bool {
            /** @var Quota|null $quota */
            $quota = Quota::allTenants()
                ->where('id', $quotaId)
                ->where('associazione_id', $associazioneId)
                ->lockForUpdate()
                ->first();

            if ($quota === null) {
                // Quota sparita: stop silenzioso (parità legacy), ack al gateway
                return true;
            }

            // Idempotenza: già pagata o transazione già registrata → ack senza ripetere
            if ($quota->data_pagamento !== null || filled($quota->gateway_transaction_id)) {
                return true;
            }

            $quota->forceFill([
                'stato' => Quota::STATO_PAGATA,
                'data_pagamento' => now()->toDateString(),
                'gateway' => $gateway,
                'gateway_transaction_id' => $transactionId,
                'metodo_pagamento' => $gateway,
            ])->save();

            /** @var Socio|null $socio */
            $socio = Socio::allTenants()->withTrashed()->find($quota->socio_id);

            if ($socio === null) {
                return true;
            }

            $this->logSocioActivity(
                $socio,
                'Pagamento Online',
                sprintf(
                    'Pagamento quota €%s anno %d via %s, transazione: %s',
                    number_format((float) $quota->importo, 2, ',', '.'),
                    $quota->anno,
                    $gateway,
                    $transactionId
                )
            );

            $config = PaymentGatewaySetting::forTenant($associazioneId);

            if ($config !== null && $config->auto_attivazione_pagamento
                && $socio->stato === Socio::STATO_IN_ATTESA_DI_PAGAMENTO) {
                $socio->forceFill([
                    'stato' => Socio::STATO_ATTIVO,
                    'data_iscrizione' => now()->toDateString(),
                ])->save();

                $this->logSocioActivity(
                    $socio,
                    'Attivazione automatica',
                    "Socio attivato dopo pagamento online ({$gateway})."
                );

                $this->createTesseraForSocio($socio);

                $notifiche[] = [$socio, new BenvenutoSocioNotification($socio)];
            }

            $notifiche[] = [$socio, new PagamentoQuotaNotification($quota->fresh() ?? $quota)];

            return true;
        });

        // Notifiche fuori transaction, sempre best-effort (mai bloccanti)
        foreach ($notifiche as [$socio, $notification]) {
            $this->notifyBestEffort($socio, $notification);
        }

        return $ok;
    }

    /**
     * Crea la tessera per il socio attivato — idempotente per (associazione, socio, anno).
     * Numero progressivo per associazione, zero-padded a 4 cifre, generato sotto lock.
     */
    public function createTesseraForSocio(Socio $socio): ?Tessera
    {
        $anno = (int) now()->year;

        $esistente = Tessera::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id)
            ->where('anno_validita', $anno)
            ->exists();

        if ($esistente) {
            return null;
        }

        /** @var Associazione|null $associazione */
        $associazione = Associazione::find($socio->associazione_id);
        $tipoScadenza = $associazione?->tipo_scadenza_default ?: Tessera::TIPO_SCADENZA_SOLARE;

        $dataScadenza = $tipoScadenza === Tessera::TIPO_SCADENZA_ANNUALE
            ? now()->addYear()->toDateString()
            : now()->endOfYear()->toDateString();

        // Progressivo sotto lock per evitare la race condition del legacy
        $maxNumero = Tessera::allTenants()
            ->where('associazione_id', $socio->associazione_id)
            ->lockForUpdate()
            ->pluck('numero_tessera')
            ->map(fn ($n) => (int) $n)
            ->max() ?? 0;

        $numeroTessera = str_pad((string) ($maxNumero + 1), 4, '0', STR_PAD_LEFT);

        $tessera = new Tessera([
            'associazione_id' => $socio->associazione_id,
            'socio_id' => $socio->id,
            'numero_tessera' => $numeroTessera,
            'anno_validita' => $anno,
            'data_emissione' => now()->toDateString(),
            'data_scadenza' => $dataScadenza,
            'stato' => Tessera::STATO_ATTIVA,
            'tipo_scadenza' => $tipoScadenza,
        ]);
        $tessera->save();

        $this->logSocioActivity(
            $socio,
            'Generazione Tessera',
            "Tessera {$numeroTessera} generata dopo pagamento online."
        );

        return $tessera;
    }

    // ------------------------------------------------------------------
    // Helper per altri flussi (es. iscrizione socio)
    // ------------------------------------------------------------------

    /**
     * Crea una quota per l'anno corrente (helper usato da altri flussi).
     * Scadenza: 31/12 anno corrente se 'solare', oggi+1 anno se 'annuale'.
     */
    public static function createQuotaForSocio(
        Socio $socio,
        float $importo,
        ?string $dataPagamento = null,
        string $tipo = 'Quota Associativa'
    ): Quota {
        /** @var Associazione|null $associazione */
        $associazione = Associazione::find($socio->associazione_id);

        $dataScadenza = ($associazione?->tipo_scadenza_default ?? 'solare') === 'annuale'
            ? now()->addYear()->toDateString()
            : now()->endOfYear()->toDateString();

        $quota = new Quota([
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'anno' => (int) now()->year,
            'importo' => $importo,
            'data_scadenza' => $dataScadenza,
            'data_pagamento' => $dataPagamento,
            'stato' => $dataPagamento !== null ? Quota::STATO_PAGATA : Quota::STATO_DA_PAGARE,
            'tipo' => $tipo,
        ]);
        $quota->save();

        return $quota;
    }

    // ------------------------------------------------------------------
    // Interni
    // ------------------------------------------------------------------

    /**
     * Log attività socio — append-only, fail-safe (mai bloccante, parità legacy).
     */
    protected function logSocioActivity(Socio $socio, string $tipo, string $descrizione, ?Utente $utente = null): void
    {
        try {
            StoricoAttivitaSocio::create([
                'associazione_id' => $socio->associazione_id,
                'socio_id' => $socio->id,
                'utente_id' => $utente?->id,
                'tipo_attivita' => $tipo,
                'descrizione' => $descrizione,
                'data_attivita' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('PaymentService::logSocioActivity', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Notifica best-effort: ogni errore è solo loggato,
     * il flusso pagamenti non fallisce mai per colpa delle notifiche.
     */
    protected function notifyBestEffort(Socio $socio, object $notification): void
    {
        try {
            $socio->notify($notification);
        } catch (\Throwable $e) {
            Log::warning('PaymentService::notifyBestEffort', [
                'socio_id' => $socio->id,
                'notification' => $notification::class,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
