<?php

namespace App\Services\Pagamenti\Gateways;

use App\Models\PaymentGatewaySetting;
use App\Models\Quota;

/**
 * Astrazione del gateway PayPal (mockata nei test).
 */
interface PayPalGatewayInterface
{
    /**
     * Crea un ordine PayPal (intent CAPTURE) e restituisce l'URL di approvazione.
     *
     * @return string|null URL "approve" su cui redirigere il socio (null su errore API)
     */
    public function createOrder(
        PaymentGatewaySetting $config,
        Quota $quota,
        string $returnUrl,
        string $cancelUrl
    ): ?string;

    /**
     * Esegue il capture dell'ordine approvato (è il webhook a completare l'incasso).
     *
     * @return array<string, mixed>|null risposta capture (null su errore API)
     */
    public function captureOrder(PaymentGatewaySetting $config, string $orderId): ?array;

    /**
     * Verifica la firma del webhook via API ufficiale
     * /v1/notifications/verify-webhook-signature (fix sicurezza rispetto al legacy).
     *
     * @param  array<string, string|null>  $headers  header PAYPAL-* della richiesta
     * @param  array<string, mixed>  $event  payload JSON decodificato
     */
    public function verifyWebhookSignature(PaymentGatewaySetting $config, array $headers, array $event): bool;
}
