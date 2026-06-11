<?php

namespace App\Services\Pagamenti\Gateways;

use App\Models\PaymentGatewaySetting;
use App\Models\Quota;

/**
 * Astrazione del gateway Stripe (mockata nei test).
 */
interface StripeGatewayInterface
{
    /**
     * Crea una Checkout Session Stripe per la quota.
     *
     * @return string|null URL di checkout su cui redirigere il socio (null su errore API)
     */
    public function createCheckoutSession(
        PaymentGatewaySetting $config,
        Quota $quota,
        string $successUrl,
        string $cancelUrl
    ): ?string;

    /**
     * Verifica la firma del webhook e restituisce l'evento come array.
     *
     * @return array<string, mixed>|null null se firma o payload non validi
     */
    public function constructWebhookEvent(string $payload, string $signatureHeader, string $webhookSecret): ?array;
}
