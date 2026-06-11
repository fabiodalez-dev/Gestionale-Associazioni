<?php

namespace App\Services\Pagamenti\Gateways;

use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use Illuminate\Support\Facades\Log;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

/**
 * Implementazione PayPal (srmklive/paypal, Orders API v2) — parità con
 * PaymentService::createPayPalOrder / handlePayPalWebhook del legacy,
 * più la verifica firma webhook (fix sicurezza §8.4 della spec).
 */
class PayPalGateway implements PayPalGatewayInterface
{
    public function createOrder(
        PaymentGatewaySetting $config,
        Quota $quota,
        string $returnUrl,
        string $cancelUrl
    ): ?string {
        $associazione = $quota->associazione;
        $currency = strtoupper($config->valuta ?: 'EUR');
        $amount = number_format((float) $quota->importo, 2, '.', '');

        try {
            $provider = $this->provider($config);

            $order = $provider->createOrder([
                'intent' => 'CAPTURE',
                'purchase_units' => [[
                    'amount' => [
                        'currency_code' => $currency,
                        'value' => $amount,
                    ],
                    // Chiave di correlazione per il webhook
                    'custom_id' => $quota->id,
                    'description' => ($associazione->nome ?? 'Associazione').' - '.($quota->tipo ?: 'Quota')." Anno {$quota->anno}",
                ]],
                // Migliorìa rispetto al legacy: ritorno esplicito sulla pagina di pagamento
                'application_context' => [
                    'return_url' => $returnUrl,
                    'cancel_url' => $cancelUrl,
                ],
            ]);

            if (! is_array($order) || empty($order['id'])) {
                Log::error('PayPalGateway::createOrder: risposta non valida', ['quota_id' => $quota->id]);

                return null;
            }

            foreach ((array) ($order['links'] ?? []) as $link) {
                if (($link['rel'] ?? null) === 'approve' && ! empty($link['href'])) {
                    return $link['href'];
                }
            }

            // Fallback legacy se manca il link approve
            $base = $config->paypal_mode === 'live'
                ? 'https://www.paypal.com/checkoutnow?token='
                : 'https://www.sandbox.paypal.com/checkoutnow?token=';

            return $base.$order['id'];
        } catch (\Throwable $e) {
            Log::error('PayPalGateway::createOrder', ['quota_id' => $quota->id, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function captureOrder(PaymentGatewaySetting $config, string $orderId): ?array
    {
        try {
            $provider = $this->provider($config);
            $result = $provider->capturePaymentOrder($orderId);

            return is_array($result) ? $result : null;
        } catch (\Throwable $e) {
            Log::error('PayPalGateway::captureOrder', ['order_id' => $orderId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    public function verifyWebhookSignature(PaymentGatewaySetting $config, array $headers, array $event): bool
    {
        if (blank($config->paypal_webhook_id)) {
            // Senza webhook_id configurato non è possibile verificare:
            // mitigazione di fatto = il capture avviene server-side con le credenziali del tenant.
            Log::warning('PayPalGateway: paypal_webhook_id non configurato, verifica firma saltata', [
                'associazione_id' => $config->associazione_id,
            ]);

            return true;
        }

        try {
            $provider = $this->provider($config);

            $response = $provider->verifyWebHook([
                'auth_algo' => $headers['paypal-auth-algo'] ?? '',
                'cert_url' => $headers['paypal-cert-url'] ?? '',
                'transmission_id' => $headers['paypal-transmission-id'] ?? '',
                'transmission_sig' => $headers['paypal-transmission-sig'] ?? '',
                'transmission_time' => $headers['paypal-transmission-time'] ?? '',
                'webhook_id' => $config->paypal_webhook_id,
                'webhook_event' => $event,
            ]);

            return is_array($response) && ($response['verification_status'] ?? null) === 'SUCCESS';
        } catch (\Throwable $e) {
            Log::error('PayPalGateway::verifyWebhookSignature', ['error' => $e->getMessage()]);

            return false;
        }
    }

    protected function provider(PaymentGatewaySetting $config): PayPalClient
    {
        $mode = $config->paypal_mode === 'live' ? 'live' : 'sandbox';

        $provider = new PayPalClient([
            'mode' => $mode,
            $mode => [
                'client_id' => (string) $config->paypal_client_id,
                'client_secret' => $config->paypal_client_secret,
                'app_id' => '',
            ],
            'payment_action' => 'Sale',
            'currency' => strtoupper($config->valuta ?: 'EUR'),
            'notify_url' => '',
            'locale' => 'it_IT',
            'validate_ssl' => true,
        ]);

        $provider->getAccessToken();

        return $provider;
    }
}
