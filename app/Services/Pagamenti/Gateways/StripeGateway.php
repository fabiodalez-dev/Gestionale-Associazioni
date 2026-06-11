<?php

namespace App\Services\Pagamenti\Gateways;

use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;

/**
 * Implementazione Stripe (stripe-php, Checkout Session) — parità con
 * PaymentService::createStripeCheckout / handleStripeWebhook del legacy.
 */
class StripeGateway implements StripeGatewayInterface
{
    public function createCheckoutSession(
        PaymentGatewaySetting $config,
        Quota $quota,
        string $successUrl,
        string $cancelUrl
    ): ?string {
        $associazione = $quota->associazione;
        $socio = $quota->socio()->withTrashed()->first();
        $currency = strtolower($config->valuta ?: 'eur');

        try {
            $client = new StripeClient($config->stripe_secret_key);

            $params = [
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency' => $currency,
                        'product_data' => [
                            'name' => ($associazione->nome ?? 'Associazione').' - '.($quota->tipo ?: 'Quota'),
                            'description' => "Anno {$quota->anno}",
                        ],
                        // round, non cast: evita errori di floating point sui centesimi
                        'unit_amount' => (int) round((float) $quota->importo * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                // Fondamentale: il webhook risale a tenant e quota da questi metadata
                'metadata' => [
                    'quota_id' => $quota->id,
                    'associazione_id' => $quota->associazione_id,
                ],
            ];

            if (filled($socio?->email)) {
                $params['customer_email'] = $socio->email;
            }

            $session = $client->checkout->sessions->create($params);

            return $session->url;
        } catch (ApiErrorException $e) {
            Log::error('StripeGateway::createCheckoutSession', ['quota_id' => $quota->id, 'error' => $e->getMessage()]);

            return null;
        }
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
