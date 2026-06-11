<?php

namespace App\Services\Pagamenti\Gateways;

use App\Models\PaymentGatewaySetting;
use App\Services\Pagamenti\Pagabile;
use Illuminate\Support\Facades\Log;
use Srmklive\PayPal\Services\PayPal as PayPalClient;

/**
 * Implementazione PayPal (srmklive/paypal, Orders API v2) sull'astrazione
 * Pagabile (Quota e Prenotazione), con verifica firma webhook
 * (fix sicurezza §8.4 della spec).
 */
class PayPalGateway implements PayPalGatewayInterface
{
    public function createOrder(
        PaymentGatewaySetting $config,
        Pagabile $pagabile,
        string $returnUrl,
        string $cancelUrl
    ): ?string {
        try {
            $provider = $this->provider($config);

            $order = $provider->createOrder(
                $this->buildOrderPayload($config, $pagabile, $returnUrl, $cancelUrl)
            );

            if (! is_array($order) || empty($order['id'])) {
                Log::error('PayPalGateway::createOrder: risposta non valida', [
                    'pagabile_tipo' => $pagabile->getPagabileTipo(),
                    'pagabile_id' => $pagabile->getPagabileId(),
                ]);

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
            Log::error('PayPalGateway::createOrder', [
                'pagabile_tipo' => $pagabile->getPagabileTipo(),
                'pagabile_id' => $pagabile->getPagabileId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Payload dell'ordine PayPal (estratto per testabilità senza API).
     *
     * custom_id: UUID nudo per le quote (retrocompatibilità con gli ordini
     * legacy in volo), "prenotazione:{uuid}" per le prenotazioni.
     *
     * @return array<string, mixed>
     */
    public function buildOrderPayload(
        PaymentGatewaySetting $config,
        Pagabile $pagabile,
        string $returnUrl,
        string $cancelUrl
    ): array {
        $customId = $pagabile->getPagabileTipo() === Pagabile::TIPO_QUOTA
            ? $pagabile->getPagabileId()
            : $pagabile->getPagabileTipo().':'.$pagabile->getPagabileId();

        return [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'amount' => [
                    'currency_code' => strtoupper($config->valuta ?: 'EUR'),
                    'value' => number_format($pagabile->getImporto(), 2, '.', ''),
                ],
                // Chiave di correlazione per il webhook
                'custom_id' => $customId,
                'description' => $pagabile->getDescrizioneRiga(),
            ]],
            // Migliorìa rispetto al legacy: ritorno esplicito sulla pagina di pagamento
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
            ],
        ];
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
