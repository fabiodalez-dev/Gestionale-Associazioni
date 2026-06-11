<?php

namespace App\Services\Pagamenti\Gateways;

use App\Models\PaymentGatewaySetting;
use App\Services\Pagamenti\Pagabile;

/**
 * Astrazione del gateway Stripe (mockata nei test).
 */
interface StripeGatewayInterface
{
    /**
     * Crea una Checkout Session Stripe per il pagabile (Quota o Prenotazione).
     *
     * I metadata della sessione sono {tipo, pagabile_id, associazione_id,
     * quota_id solo se tipo=quota}: il webhook risale a tenant ed entità da lì.
     *
     * @return string|null URL di checkout su cui redirigere il pagatore (null su errore API)
     */
    public function createCheckoutSession(
        PaymentGatewaySetting $config,
        Pagabile $pagabile,
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
