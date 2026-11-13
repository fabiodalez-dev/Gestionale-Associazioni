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
use Carbon\CarbonInterface;
use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\Cache;
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
    use DetectsConcurrencyErrors;

    public const TOKEN_EXPIRY_HOURS = 72;

    /**
     * Dopo un pagamento online il token del link pubblico resta valido al
     * massimo per questa finestra: il tempo di tornare dal gateway e
     * scaricare la ricevuta. Poi la ricevuta è disponibile solo dall'area
     * riservata (e via email), non più da un link che gira in chiaro.
     */
    public const TOKEN_GRACE_AFTER_PAYMENT_HOURS = 1;

    /** Durata della deduplica per id evento dei webhook Stripe. */
    public const WEBHOOK_DEDUP_TTL_HOURS = 24;

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
     * logga l'attività socio e notifica best-effort. Come per il pagamento
     * online, il socio già attivo ottiene la tessera dell'anno della quota.
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

            $this->emettiTesseraRinnovoBestEffort($socio, $quota);
        }

        $this->completaPagamentoOffline($quota, $socio);
    }

    /**
     * Effetti di un incasso offline già registrato sulla quota: notifica di
     * pagamento, ricevuta numerata (f.21) e movimento di prima nota (f.13).
     * Condiviso da registerOfflinePayment e dalla conferma del pagamento
     * della pre-iscrizione allo sportello. Tutto best-effort e idempotente.
     */
    public function completaPagamentoOffline(Quota $quota, ?Socio $socio): void
    {
        if ($socio !== null) {
            $this->notifyBestEffort($socio, new PagamentoQuotaNotification($quota->fresh() ?? $quota));
        }

        // Ricevuta numerata per anno (f.21): emissione + email best-effort
        $this->emettiRicevutaBestEffort($quota->id);

        // Prima nota (f.13 piano "Gestione 2"): movimento automatico
        // di entrata, idempotente e best-effort
        app(PrimaNotaService::class)->registraIncassoQuotaBestEffort($quota->id);
    }

    /**
     * Rinnovo (f.1 piano Vita sociale): un socio GIÀ attivo che paga la quota
     * di un anno ottiene la tessera di quell'anno emessa se assente
     * (idempotente, best-effort: un errore di emissione non invalida il
     * pagamento registrato).
     */
    protected function emettiTesseraRinnovoBestEffort(Socio $socio, Quota $quota): void
    {
        if (! $socio->isAttivo() || $socio->trashed()) {
            return;
        }

        try {
            app(TesseraIssuer::class)->emettiSeAssente($socio, (int) $quota->anno);
        } catch (\Throwable $e) {
            // Deadlock/lock timeout dentro una transazione esterna (es.
            // onPaymentCompleted): il database ha già annullato o compromesso
            // la transazione, quindi non va ingoiato. Si rilancia perché il
            // pagamento fallisca in modo esplicito e il webhook venga ritentato.
            if (DB::transactionLevel() > 0 && $this->causedByConcurrencyError($e)) {
                throw $e;
            }

            Log::warning('PaymentService: emissione tessera di rinnovo fallita', [
                'socio_id' => $socio->id,
                'quota_id' => $quota->id,
                'error' => $e->getMessage(),
            ]);
        }
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
     * Verifica firma obbligatoria con il webhook secret per-tenant; poi,
     * sull'evento firmato: deduplica per id evento (cache 24h), controllo
     * della modalità live/test rispetto alla chiave configurata (quando
     * ricavabile dal prefisso sk_/rk_), e confronto di stato del pagamento,
     * importo e valuta della sessione con il pagabile prima di accreditare.
     */
    public function handleStripeWebhook(string $payload, string $signatureHeader, string $associazioneId): EsitoWebhook
    {
        $config = PaymentGatewaySetting::forTenant($associazioneId);

        if ($config === null || blank($config->stripe_webhook_secret)) {
            return EsitoWebhook::FALLITO;
        }

        $event = $this->stripe->constructWebhookEvent($payload, $signatureHeader, $config->stripe_webhook_secret);

        if ($event === null) {
            return EsitoWebhook::FALLITO;
        }

        if (($event['type'] ?? null) !== 'checkout.session.completed') {
            // Eventi diversi: ack senza azione (evita retry inutili da Stripe)
            return EsitoWebhook::OK;
        }

        // Modalità live/test dell'evento coerente con la chiave del tenant
        // (solo quando il prefisso della chiave la rende ricavabile)
        $livemodeAttesa = $config->stripeLivemodeAttesa();

        if ($livemodeAttesa !== null && array_key_exists('livemode', $event)
            && (bool) $event['livemode'] !== $livemodeAttesa) {
            Log::warning('PaymentService: webhook Stripe con livemode incoerente con la chiave configurata', [
                'associazione_id' => $associazioneId,
                'event_id' => $event['id'] ?? null,
                'livemode_evento' => (bool) $event['livemode'],
                'livemode_atteso' => $livemodeAttesa,
            ]);

            return EsitoWebhook::IGNORATO;
        }

        // Deduplica per id evento: Stripe può consegnare lo stesso evento più
        // volte; la chiave va presa SOLO dopo la verifica della firma, così
        // una richiesta non firmata non può "bruciare" l'id di un evento vero
        $chiaveDedup = $this->chiaveDedupStripe($associazioneId, $event);

        if ($chiaveDedup !== null && ! Cache::add($chiaveDedup, true, now()->addHours(self::WEBHOOK_DEDUP_TTL_HOURS))) {
            Log::info('PaymentService: webhook Stripe già elaborato, replay ignorato', [
                'associazione_id' => $associazioneId,
                'event_id' => $event['id'],
            ]);

            return EsitoWebhook::OK;
        }

        $esito = $this->elaboraSessioneStripe($event, $config, $associazioneId);

        if ($esito === EsitoWebhook::FALLITO && $chiaveDedup !== null) {
            // Elaborazione non riuscita: libera la chiave perché il retry di
            // Stripe venga elaborato davvero
            Cache::forget($chiaveDedup);
        }

        return $esito;
    }

    /**
     * Chiave di deduplica dell'evento Stripe (null se l'evento non porta un id).
     *
     * @param  array<string, mixed>  $event
     */
    protected function chiaveDedupStripe(string $associazioneId, array $event): ?string
    {
        $eventId = $event['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            return null;
        }

        return 'webhook-stripe:'.$associazioneId.':'.$eventId;
    }

    /**
     * Elabora una checkout.session.completed già verificata e deduplicata.
     *
     * @param  array<string, mixed>  $event
     */
    protected function elaboraSessioneStripe(array $event, PaymentGatewaySetting $config, string $associazioneId): EsitoWebhook
    {
        $session = (array) data_get($event, 'data.object', []);
        $transactionId = (string) (data_get($session, 'payment_intent') ?: data_get($session, 'id', ''));

        // Dispatch per tipo: default 'quota' per retrocompatibilità
        // con le sessioni checkout legacy in volo (senza metadata.tipo)
        $tipo = (string) (data_get($session, 'metadata.tipo') ?: Pagabile::TIPO_QUOTA);

        $pagabileId = $tipo === Pagabile::TIPO_QUOTA
            ? (data_get($session, 'metadata.quota_id') ?: data_get($session, 'metadata.pagabile_id'))
            : data_get($session, 'metadata.pagabile_id');

        if (blank($pagabileId)) {
            Log::warning('PaymentService: webhook Stripe senza id del pagabile nei metadata', ['tipo' => $tipo]);

            return EsitoWebhook::FALLITO;
        }

        $pagabileId = (string) $pagabileId;

        // Stato, importo e valuta della sessione devono corrispondere al
        // pagabile: altrimenti nessun accredito (ack "ignorato", un retry
        // non cambierebbe nulla)
        $pagabile = $this->trovaPagabile($tipo, $pagabileId, $associazioneId);

        if ($pagabile !== null && ! $this->sessioneStripeCoerente($session, $pagabile, $config, $event['id'] ?? null)) {
            return EsitoWebhook::IGNORATO;
        }

        $ok = match ($tipo) {
            Pagabile::TIPO_PRENOTAZIONE => $this->onPagamentoPrenotazioneCompletato($pagabileId, $associazioneId, 'stripe', $transactionId),
            Pagabile::TIPO_DONAZIONE => $this->onPagamentoDonazioneCompletato($pagabileId, $associazioneId, 'stripe', $transactionId),
            default => $this->onPaymentCompleted($pagabileId, $associazioneId, 'stripe', $transactionId),
        };

        return $ok ? EsitoWebhook::OK : EsitoWebhook::FALLITO;
    }

    /**
     * Confronta payment_status, amount_total e currency della Checkout
     * Session con il pagabile e la valuta del tenant. I campi assenti
     * (mai in un evento reale di Stripe) vengono saltati con un warning.
     *
     * @param  array<string, mixed>  $session
     */
    protected function sessioneStripeCoerente(array $session, Pagabile $pagabile, PaymentGatewaySetting $config, mixed $eventId): bool
    {
        $contesto = [
            'event_id' => $eventId,
            'tipo' => $pagabile->getPagabileTipo(),
            'pagabile_id' => $pagabile->getPagabileId(),
        ];

        if (array_key_exists('payment_status', $session) && $session['payment_status'] !== 'paid') {
            Log::warning('PaymentService: sessione Stripe completata ma non pagata, nessun accredito', $contesto + [
                'payment_status' => $session['payment_status'],
            ]);

            return false;
        }

        if (array_key_exists('amount_total', $session)) {
            $atteso = (int) round($pagabile->getImporto() * 100);

            if ((int) $session['amount_total'] !== $atteso) {
                Log::warning('PaymentService: importo Stripe diverso da quello del pagabile, nessun accredito', $contesto + [
                    'amount_total' => $session['amount_total'],
                    'atteso' => $atteso,
                ]);

                return false;
            }
        } else {
            Log::warning('PaymentService: amount_total assente nella sessione Stripe, confronto importo saltato', $contesto);
        }

        if (array_key_exists('currency', $session)) {
            $attesa = strtolower($config->valuta ?: 'eur');

            if (strtolower((string) $session['currency']) !== $attesa) {
                Log::warning('PaymentService: valuta Stripe diversa da quella del tenant, nessun accredito', $contesto + [
                    'currency' => $session['currency'],
                    'attesa' => $attesa,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Gestisce il webhook PayPal per il tenant indicato: verifica firma
     * obbligatoria (paypal_webhook_id richiesto) + CAPTURE dell'ordine
     * approvato. La fonte di verità per custom_id e importo è SOLO la
     * risposta del capture (o i dettagli dell'ordine letti via API se il
     * capture non riporta il custom_id), mai l'evento ricevuto.
     *
     * @param  array<string, mixed>  $event
     * @param  array<string, string|null>  $headers
     */
    public function handlePayPalWebhook(array $event, array $headers, string $associazioneId): EsitoWebhook
    {
        $config = PaymentGatewaySetting::forTenant($associazioneId);

        if ($config === null || ! $config->isPayPalOperational()) {
            return EsitoWebhook::FALLITO;
        }

        // Fix sicurezza rispetto al legacy: verifica firma ufficiale
        if (! $this->paypal->verifyWebhookSignature($config, $headers, $event)) {
            Log::warning('PaymentService: firma webhook PayPal non valida', ['associazione_id' => $associazioneId]);

            return EsitoWebhook::FALLITO;
        }

        if (($event['event_type'] ?? null) !== 'CHECKOUT.ORDER.APPROVED') {
            // Altri eventi: ack senza azione
            return EsitoWebhook::OK;
        }

        $orderId = data_get($event, 'resource.id');

        if (blank($orderId)) {
            return EsitoWebhook::FALLITO;
        }

        $orderId = (string) $orderId;

        // È il webhook a completare l'incasso (capture server-side)
        $capture = $this->paypal->captureOrder($config, $orderId);

        if ($capture === null || ($capture['status'] ?? null) !== 'COMPLETED') {
            Log::warning('PaymentService: capture PayPal non completato', ['order_id' => $orderId]);

            return EsitoWebhook::FALLITO;
        }

        $customId = data_get($capture, 'purchase_units.0.payments.captures.0.custom_id')
            ?: data_get($capture, 'purchase_units.0.custom_id');

        if (blank($customId)) {
            // Il capture non riporta il custom_id: lo si legge dall'ordine via
            // API, mai dall'evento (che non è una fonte affidabile)
            $dettagli = $this->paypal->showOrderDetails($config, $orderId);

            $customId = data_get($dettagli, 'purchase_units.0.custom_id')
                ?: data_get($dettagli, 'purchase_units.0.payments.captures.0.custom_id');
        }

        if (blank($customId)) {
            Log::warning('PaymentService: custom_id assente nel capture e nei dettagli ordine PayPal', ['order_id' => $orderId]);

            return EsitoWebhook::FALLITO;
        }

        $captureId = data_get($capture, 'purchase_units.0.payments.captures.0.id');
        $transactionId = (string) ($captureId ?: $orderId);

        // custom_id "prenotazione:{uuid}" / "donazione:{uuid}" → tipo esplicito;
        // UUID nudo → quota (retrocompatibilità con gli ordini legacy in volo)
        [$tipo, $pagabileId] = $this->parseCustomId((string) $customId);

        // Importo e valuta catturati devono corrispondere al pagabile:
        // altrimenti nessun accredito (ack "ignorato" a PayPal)
        $pagabile = $this->trovaPagabile($tipo, $pagabileId, $associazioneId);

        if ($pagabile !== null && ! $this->captureCoerente($capture, $pagabile, $config, $orderId)) {
            return EsitoWebhook::IGNORATO;
        }

        $ok = match ($tipo) {
            Pagabile::TIPO_PRENOTAZIONE => $this->onPagamentoPrenotazioneCompletato($pagabileId, $associazioneId, 'paypal', $transactionId),
            Pagabile::TIPO_DONAZIONE => $this->onPagamentoDonazioneCompletato($pagabileId, $associazioneId, 'paypal', $transactionId),
            default => $this->onPaymentCompleted($pagabileId, $associazioneId, 'paypal', $transactionId),
        };

        return $ok ? EsitoWebhook::OK : EsitoWebhook::FALLITO;
    }

    /**
     * Confronta importo e valuta del capture PayPal con il pagabile e la
     * valuta del tenant. Un capture senza importo (mai in una risposta
     * reale dell'API) viene accettato con un warning.
     *
     * @param  array<string, mixed>  $capture
     */
    protected function captureCoerente(array $capture, Pagabile $pagabile, PaymentGatewaySetting $config, string $orderId): bool
    {
        $contesto = [
            'order_id' => $orderId,
            'tipo' => $pagabile->getPagabileTipo(),
            'pagabile_id' => $pagabile->getPagabileId(),
        ];

        $amount = data_get($capture, 'purchase_units.0.payments.captures.0.amount');

        if (! is_array($amount) || ! array_key_exists('value', $amount)) {
            Log::warning('PaymentService: importo assente nel capture PayPal, confronto importo saltato', $contesto);

            return true;
        }

        $catturato = round((float) $amount['value'], 2);
        $atteso = round($pagabile->getImporto(), 2);

        if (abs($catturato - $atteso) >= 0.005) {
            Log::warning('PaymentService: importo PayPal diverso da quello del pagabile, nessun accredito', $contesto + [
                'catturato' => $amount['value'],
                'atteso' => number_format($atteso, 2, '.', ''),
            ]);

            return false;
        }

        if (array_key_exists('currency_code', $amount)) {
            $attesa = strtoupper($config->valuta ?: 'EUR');

            if (strtoupper((string) $amount['currency_code']) !== $attesa) {
                Log::warning('PaymentService: valuta PayPal diversa da quella del tenant, nessun accredito', $contesto + [
                    'currency_code' => $amount['currency_code'],
                    'attesa' => $attesa,
                ]);

                return false;
            }
        }

        return true;
    }

    /**
     * Carica il pagabile del tenant per il confronto importi (null se non
     * esiste: in quel caso decidono i gestori post-pagamento, che ack-ano).
     */
    protected function trovaPagabile(string $tipo, string $pagabileId, string $associazioneId): ?Pagabile
    {
        $query = match ($tipo) {
            Pagabile::TIPO_PRENOTAZIONE => Prenotazione::allTenants(),
            Pagabile::TIPO_DONAZIONE => Donazione::allTenants(),
            default => Quota::allTenants(),
        };

        /** @var Pagabile|null $pagabile */
        $pagabile = $query->where('associazione_id', $associazioneId)->find($pagabileId);

        return $pagabile;
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
                if ($quota->gateway_transaction_id !== $transactionId) {
                    // Non è il replay dello stesso evento: è un SECONDO incasso
                    // sulla stessa quota (es. due checkout aperti in parallelo o
                    // quota già registrata offline). Nessun accredito doppio, ma
                    // il denaro è stato incassato: warning con la transazione da
                    // rimborsare
                    Log::warning('PaymentService: pagamento online su quota già pagata, transazione da rimborsare', [
                        'quota_id' => $quota->id,
                        'associazione_id' => $associazioneId,
                        'gateway' => $gateway,
                        'transaction_id_duplicato' => $transactionId,
                        'transaction_id_registrato' => $quota->gateway_transaction_id,
                        'metodo_registrato' => $quota->metodo_pagamento,
                        'data_pagamento' => $quota->data_pagamento?->toDateString(),
                    ]);
                }

                return true;
            }

            // Lock sulla riga del socio prima di ogni altra lettura o scrittura
            // (ordine quota → socio → contatore tessere): due pagamenti dello
            // stesso socio si serializzano qui. Preso più avanti, il secondo
            // vedrebbe uno snapshot senza la tessera appena emessa dal primo,
            // oppure andrebbe in deadlock con i lock condivisi delle foreign
            // key presi dal log attività
            /** @var Socio|null $socio */
            $socio = Socio::allTenants()->withTrashed()->lockForUpdate()->find($quota->socio_id);

            $quota->forceFill([
                'stato' => Quota::STATO_PAGATA,
                'data_pagamento' => now()->toDateString(),
                'gateway' => $gateway,
                'gateway_transaction_id' => $transactionId,
                'metodo_pagamento' => $gateway,
                // Il link pubblico ha fatto il suo lavoro: resta valido solo per
                // la finestra di grazia (ritorno dal gateway + ricevuta)
                'payment_token_expires' => $this->scadenzaTokenDopoPagamento($quota),
            ])->save();

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
            // emessa/rinnovata se assente
            if ($eraGiaAttivo) {
                $this->emettiTesseraRinnovoBestEffort($socio, $quota);
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
     * Emissione delegata a TesseraIssuer: numerazione unificata {anno}{NNNN}
     * (contatore sotto lock), etichetta template e QR di verifica firmato.
     */
    public function createTesseraForSocio(Socio $socio): ?Tessera
    {
        $anno = (int) now()->year;

        // Esistenza ricontrollata sotto lock del socio; una tessera emessa
        // nel frattempo da un altro flusso vale come "già presente" (issue #21)
        $tessera = app(TesseraIssuer::class)->emettiSeNonPresente($socio, ['anno_validita' => $anno]);

        if ($tessera === null) {
            return null;
        }

        $this->logSocioActivity(
            $socio,
            'Generazione Tessera',
            "Tessera {$tessera->numero_tessera} generata dopo pagamento online."
        );

        return $tessera;
    }

    // ------------------------------------------------------------------
    // Helper per altri flussi (es. iscrizione socio)
    // ------------------------------------------------------------------

    /**
     * Crea una quota per l'anno corrente o per l'anno indicato (helper usato
     * da altri flussi, es. rinnovo anticipato per l'anno successivo).
     * Scadenza: 31/12 dell'anno della quota se 'solare', oggi+1 anno se 'annuale'.
     */
    public static function createQuotaForSocio(
        Socio $socio,
        float $importo,
        ?string $dataPagamento = null,
        string $tipo = 'Quota Associativa',
        ?int $anno = null,
    ): Quota {
        /** @var Associazione|null $associazione */
        $associazione = Associazione::find($socio->associazione_id);

        $anno = $anno ?? (int) now()->year;

        $dataScadenza = $associazione?->tesseramento()->scadenzaAnnuale()
            ? now()->addYear()->toDateString()
            : now()->setDate($anno, 12, 31)->toDateString();

        $quota = new Quota([
            'socio_id' => $socio->id,
            'associazione_id' => $socio->associazione_id,
            'anno' => $anno,
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
     * Scadenza del token di pagamento dopo l'incasso online: la più vicina
     * tra quella attuale e ora + finestra di grazia (null resta null).
     */
    protected function scadenzaTokenDopoPagamento(Quota $quota): ?CarbonInterface
    {
        if (blank($quota->payment_token)) {
            return $quota->payment_token_expires;
        }

        $grazia = now()->addHours(self::TOKEN_GRACE_AFTER_PAYMENT_HOURS);
        $attuale = $quota->payment_token_expires;

        return $attuale !== null && $attuale->lt($grazia) ? $attuale : $grazia;
    }

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
