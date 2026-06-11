<?php

namespace App\Services\Pagamenti\Gateways;

use App\Models\PaymentGatewaySetting;
use App\Services\Pagamenti\Pagabile;

/**
 * Astrazione del gateway PayPal (mockata nei test).
 */
interface PayPalGatewayInterface
{
    /**
     * Crea un ordine PayPal (intent CAPTURE) per il pagabile e restituisce
     * l'URL di approvazione.
     *
     * La chiave di correlazione del webhook è custom_id:
     * "prenotazione:{uuid}" per le prenotazioni, UUID nudo per le quote
     * (retrocompatibilità con gli ordini legacy in volo).
     *
     * @return string|null URL "approve" su cui redirigere il pagatore (null su errore API)
     */
    public function createOrder(
        PaymentGatewaySetting $config,
        Pagabile $pagabile,
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
