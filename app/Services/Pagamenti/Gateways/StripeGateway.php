<?php

namespace App\Services\Pagamenti\Gateways;

use App\Models\PaymentGatewaySetting;
use App\Services\Pagamenti\Pagabile;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Implementazione Stripe (stripe-php, Checkout Session) sull'astrazione
 * Pagabile (Quota e Prenotazione): stessa sessione, metadata diversi.
 */
class StripeGateway implements StripeGatewayInterface
{
    public function createCheckoutSession(
        PaymentGatewaySetting $config,
        Pagabile $pagabile,
        string $successUrl,
        string $cancelUrl
    ): ?string {
        try {
            $client = new StripeClient($config->stripe_secret_key);

            $session = $client->checkout->sessions->create(
                $this->buildCheckoutParams($config, $pagabile, $successUrl, $cancelUrl)
            );

            return $session->url;
        } catch (ApiErrorException $e) {
            Log::error('StripeGateway::createCheckoutSession', [
                'pagabile_tipo' => $pagabile->getPagabileTipo(),
                'pagabile_id' => $pagabile->getPagabileId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Parametri della Checkout Session (estratti per testabilità senza API).
     *
     * Metadata: {tipo, pagabile_id, associazione_id} + quota_id SOLO se
     * tipo=quota (retrocompatibilità con il webhook handler legacy).
     *
     * @return array<string, mixed>
     */
    public function buildCheckoutParams(
        PaymentGatewaySetting $config,
        Pagabile $pagabile,
        string $successUrl,
        string $cancelUrl
    ): array {
        $metadata = [
            'tipo' => $pagabile->getPagabileTipo(),
            'pagabile_id' => $pagabile->getPagabileId(),
            'associazione_id' => $pagabile->getAssociazioneId(),
        ];

        if ($pagabile->getPagabileTipo() === Pagabile::TIPO_QUOTA) {
            $metadata['quota_id'] = $pagabile->getPagabileId();
        }

        $params = [
            'payment_method_types' => ['card'],
            'line_items' => [[
                'price_data' => [
                    'currency' => strtolower($config->valuta ?: 'eur'),
                    'product_data' => [
                        'name' => $pagabile->getDescrizioneRiga(),
                    ],
                    // round, non cast: evita errori di floating point sui centesimi
                    'unit_amount' => (int) round($pagabile->getImporto() * 100),
                ],
                'quantity' => 1,
            ]],
            'mode' => 'payment',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            // Fondamentale: il webhook risale a tenant ed entità da questi metadata
            'metadata' => $metadata,
        ];

        $email = $pagabile->getEmailPagatore();

        if (filled($email)) {
            $params['customer_email'] = $email;
        }

        return $params;
    }

    public function constructWebhookEvent(string $payload, string $signatureHeader, string $webhookSecret): ?array
    {
        try {
            $event = Webhook::constructEvent($payload, $signatureHeader, $webhookSecret);

            return $event->toArray();
        } catch (SignatureVerificationException|\UnexpectedValueException $e) {
            Log::warning('StripeGateway: firma webhook non valida', ['error' => $e->getMessage()]);

            return null;
        }
    }
}
