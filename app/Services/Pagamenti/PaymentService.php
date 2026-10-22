<?php

namespace App\Services\Pagamenti;

use App\Models\Associazione;
use App\Models\Donazione;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Models\Utente;
use App\Notifications\Quote\BenvenutoSocioNotification;
use App\Notifications\Quote\PagamentoQuotaNotification;
use App\Notifications\Quote\RicevutaQuotaNotification;
use App\Services\Economia\DonazioneService;
use App\Services\Economia\PrimaNotaService;
use App\Services\Pagamenti\Gateways\PayPalGatewayInterface;
use App\Services\Pagamenti\Gateways\StripeGatewayInterface;
use App\Services\Prenotazioni\Contracts\GestiscePagamentoPrenotazione;
use App\Services\Tessere\TesseraIssuer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestratore pagamenti (parità con includes/PaymentService.php legacy):
 * token/link di pagamento, checkout Stripe/PayPal su Pagabile (quote e
 * prenotazioni), webhook con dispatch per tipo (default 'quota' per
 * retrocompatibilità con le sessioni in volo), post-pagamento quota
 * (auto-attivazione socio + tessera + notifiche), helper createQuotaForSocio.
 */
class PaymentService
{
    public const TOKEN_EXPIRY_HOURS = 72;

    public function __construct(
        protected StripeGatewayInterface $stripe,
        protected PayPalGatewayInterface $paypal,
        protected GestiscePagamentoPrenotazione $prenotazioni,
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

        // Ricevuta numerata per anno (f.21): emissione + email best-effort
        $this->emettiRicevutaBestEffort($quota->id);

        // Prima nota (f.13 piano "Gestione 2"): movimento automatico
        // di entrata, idempotente e best-effort
        app(PrimaNotaService::class)->registraIncassoQuotaBestEffort($quota->id);
    }

    // ------------------------------------------------------------------
    // Checkout
    // ------------------------------------------------------------------

    public function createStripeCheckout(Quota $quota, string $returnUrl, string $cancelUrl): ?string
    {
        return $this->stripeCheckoutFor($quota, $returnUrl, $cancelUrl);
    }

    public function createPayPalOrder(Quota $quota, string $returnUrl, string $cancelUrl): ?string
    {
        return $this->payPalOrderFor($quota, $returnUrl, $cancelUrl);
    }

    public function createStripeCheckoutPrenotazione(Prenotazione $prenotazione, string $returnUrl, string $cancelUrl): ?string
    {
        return $this->stripeCheckoutFor($prenotazione, $returnUrl, $cancelUrl);
    }

    public function createPayPalOrderPrenotazione(Prenotazione $prenotazione, string $returnUrl, string $cancelUrl): ?string
    {
        return $this->payPalOrderFor($prenotazione, $returnUrl, $cancelUrl);
    }

    public function createStripeCheckoutDonazione(Donazione $donazione, string $returnUrl, string $cancelUrl): ?string
    {
        return $this->stripeCheckoutFor($donazione, $returnUrl, $cancelUrl);
    }

    public function createPayPalOrderDonazione(Donazione $donazione, string $returnUrl, string $cancelUrl): ?string
    {
        return $this->payPalOrderFor($donazione, $returnUrl, $cancelUrl);
    }

    protected function stripeCheckoutFor(Pagabile $pagabile, string $returnUrl, string $cancelUrl): ?string
    {
        $config = PaymentGatewaySetting::forTenant($pagabile->getAssociazioneId());

        if ($config === null || ! $config->isStripeOperational()) {
            return null;
        }

        return $this->stripe->createCheckoutSession($config, $pagabile, $returnUrl, $cancelUrl);
    }

    protected function payPalOrderFor(Pagabile $pagabile, string $returnUrl, string $cancelUrl): ?string
    {
        $config = PaymentGatewaySetting::forTenant($pagabile->getAssociazioneId());

        if ($config === null || ! $config->isPayPalOperational()) {
            return null;
        }

        return $this->paypal->createOrder($config, $pagabile, $returnUrl, $cancelUrl);
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
        $transactionId = (string) (data_get($session, 'payment_intent') ?: data_get($session, 'id', ''));

        // Dispatch per tipo: default 'quota' per retrocompatibilità
        // con le sessioni checkout legacy in volo (senza metadata.tipo)
        $tipo = (string) (data_get($session, 'metadata.tipo') ?: Pagabile::TIPO_QUOTA);

        if ($tipo === Pagabile::TIPO_PRENOTAZIONE) {
            $prenotazioneId = data_get($session, 'metadata.pagabile_id');

            if (blank($prenotazioneId)) {
                Log::warning('PaymentService: webhook Stripe prenotazione senza pagabile_id nei metadata');

                return false;
            }

            return $this->onPagamentoPrenotazioneCompletato((string) $prenotazioneId, $associazioneId, 'stripe', $transactionId);
        }

        if ($tipo === Pagabile::TIPO_DONAZIONE) {
            $donazioneId = data_get($session, 'metadata.pagabile_id');

            if (blank($donazioneId)) {
                Log::warning('PaymentService: webhook Stripe donazione senza pagabile_id nei metadata');

                return false;
            }

            return $this->onPagamentoDonazioneCompletato((string) $donazioneId, $associazioneId, 'stripe', $transactionId);
        }

        $quotaId = data_get($session, 'metadata.quota_id') ?: data_get($session, 'metadata.pagabile_id');

        if (blank($quotaId)) {
            Log::warning('PaymentService: webhook Stripe senza quota_id nei metadata');

            return false;
        }

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

        $customId = data_get($capture, 'purchase_units.0.payments.captures.0.custom_id')
            ?: data_get($capture, 'purchase_units.0.custom_id')
            ?: data_get($event, 'resource.purchase_units.0.custom_id');

        if (blank($customId)) {
            return false;
        }

        $captureId = data_get($capture, 'purchase_units.0.payments.captures.0.id');
        $transactionId = (string) ($captureId ?: $orderId);

        // custom_id "prenotazione:{uuid}" → prenotazione;
        // UUID nudo → quota (retrocompatibilità con gli ordini legacy in volo)
        [$tipo, $pagabileId] = $this->parseCustomId((string) $customId);

        if ($tipo === Pagabile::TIPO_PRENOTAZIONE) {
            return $this->onPagamentoPrenotazioneCompletato($pagabileId, $associazioneId, 'paypal', $transactionId);
        }

        if ($tipo === Pagabile::TIPO_DONAZIONE) {
            return $this->onPagamentoDonazioneCompletato($pagabileId, $associazioneId, 'paypal', $transactionId);
        }

        return $this->onPaymentCompleted($pagabileId, $associazioneId, 'paypal', $transactionId);
    }

    /**
     * Scompone il custom_id PayPal in [tipo, id].
     *
     * @return array{0: string, 1: string}
     */
    protected function parseCustomId(string $customId): array
    {
        foreach ([Pagabile::TIPO_PRENOTAZIONE, Pagabile::TIPO_DONAZIONE] as $tipo) {
            $prefisso = $tipo.':';

            if (str_starts_with($customId, $prefisso)) {
                return [$tipo, substr($customId, strlen($prefisso))];
            }
        }

        return [Pagabile::TIPO_QUOTA, $customId];
    }

    // ------------------------------------------------------------------
    // Post-pagamento prenotazione
    // ------------------------------------------------------------------

    /**
     * Webhook con tipo=prenotazione: dispatch a
     * PrenotazioneService::onPagamentoCompletato (transizione di stato sotto
     * lock per-evento, idempotente sul replay come per le quote — già pagata
     * → ack senza effetti — e riconferma di una scaduta anche in overbooking).
     *
     * Ack SEMPRE al gateway: anche una prenotazione inesistente (null) non
     * va ritentata (parità col flusso quote).
     */
    public function onPagamentoPrenotazioneCompletato(string $prenotazioneId, string $associazioneId, string $gateway, string $transactionId): bool
    {
        $this->prenotazioni->onPagamentoCompletato($prenotazioneId, $associazioneId, $gateway, $transactionId);

        return true;
    }

    // ------------------------------------------------------------------
    // Post-pagamento donazione (f.15 piano "Gestione 2")
    // ------------------------------------------------------------------

    /**
     * Webhook con tipo=donazione: dispatch a DonazioneService (modulo
     * economia) — idempotente sul replay come per quote e prenotazioni.
     * Risolto via container per non toccare il costruttore condiviso.
     */
    public function onPagamentoDonazioneCompletato(string $donazioneId, string $associazioneId, string $gateway, string $transactionId): bool
    {
        return app(DonazioneService::class)
            ->onPagamentoCompletato($donazioneId, $associazioneId, $gateway, $transactionId);
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

            $eraGiaAttivo = $socio->stato === Socio::STATO_ATTIVO;

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

            // Rinnovo self-service (f.1 piano Vita sociale): un socio GIÀ attivo
            // che paga la quota dell'anno ottiene la tessera dell'anno
            // emessa/rinnovata se assente (idempotente, best-effort:
            // un errore di emissione non invalida il pagamento registrato).
            if ($eraGiaAttivo && ! $socio->trashed()) {
                try {
                    app(TesseraIssuer::class)->emettiSeAssente($socio, (int) $quota->anno);
                } catch (\Throwable $e) {
                    Log::warning('PaymentService: emissione tessera di rinnovo fallita', [
                        'socio_id' => $socio->id,
                        'quota_id' => $quota->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $notifiche[] = [$socio, new PagamentoQuotaNotification($quota->fresh() ?? $quota)];

            return true;
        });

        // Notifiche fuori transaction, sempre best-effort (mai bloccanti)
        foreach ($notifiche as [$socio, $notification]) {
            $this->notifyBestEffort($socio, $notification);
        }

        // Ricevuta numerata per anno (f.21): fuori transaction (numerazione
        // in transazione propria), idempotente sul replay del webhook e
        // no-op se la quota non risulta pagata
        $this->emettiRicevutaBestEffort($quotaId);

        // Prima nota (f.13 piano "Gestione 2"): movimento automatico di
        // entrata — idempotente (unique su origine) e best-effort, quindi
        // sicuro anche sul replay del webhook
        app(PrimaNotaService::class)->registraIncassoQuotaBestEffort($quotaId);

        return $ok;
    }

    /**
     * Emette (idempotente) la ricevuta numerata della quota pagata e invia
     * la notifica con PDF SOLO alla prima emissione (f.21). Best-effort:
     * nessun errore di ricevuta deve far fallire il flusso di pagamento.
     */
    protected function emettiRicevutaBestEffort(string $quotaId): void
    {
        try {
            /** @var Quota|null $quota */
            $quota = Quota::allTenants()->find($quotaId);

            if ($quota === null) {
                return;
            }

            $ricevuta = app(RicevutaService::class)->emettiPerQuota($quota);

            if ($ricevuta === null || ! $ricevuta->wasRecentlyCreated) {
                // Quota non pagata o ricevuta già emessa in precedenza:
                // nessuna nuova email
                return;
            }

            /** @var Socio|null $socio */
            $socio = Socio::allTenants()->withTrashed()->find($quota->socio_id);

            if ($socio !== null) {
                $this->notifyBestEffort($socio, new RicevutaQuotaNotification($ricevuta));
            }
        } catch (\Throwable $e) {
            Log::warning('PaymentService::emettiRicevutaBestEffort', [
                'quota_id' => $quotaId,
                'error' => $e->getMessage(),
            ]);
        }
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
        $tipoScadenza = $associazione?->tesseramento()->tipo_scadenza_default ?? Tessera::TIPO_SCADENZA_SOLARE;

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

        $dataScadenza = $associazione?->tesseramento()->scadenzaAnnuale()
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
