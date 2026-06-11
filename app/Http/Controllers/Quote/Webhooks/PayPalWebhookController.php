<?php

namespace App\Http\Controllers\Quote\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Quota;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook PayPal (ex api/webhooks/paypal.php).
 * Pubblico, senza CSRF; il tenant è risalito dalla quota (custom_id);
 * sicurezza = verifica firma (se paypal_webhook_id configurato) + capture server-side.
 */
class PayPalWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentService $paymentService): JsonResponse
    {
        try {
            $payload = $request->getContent();

            if ($payload === '') {
                return response()->json(['error' => 'Missing payload'], 400);
            }

            $event = json_decode($payload, true);

            if (! is_array($event)) {
                return response()->json(['error' => 'Invalid JSON'], 400);
            }

            $quotaId = data_get($event, 'resource.purchase_units.0.custom_id');

            if (blank($quotaId)) {
                // Eventi non-order: ack per non far ritentare PayPal
                return response()->json(['status' => 'ignored', 'reason' => 'no custom_id in resource']);
            }

            if (! preg_match('/^[a-f0-9\-]{36}$/i', (string) $quotaId)) {
                return response()->json(['error' => 'Invalid quota id'], 400);
            }

            /** @var Quota|null $quota */
            $quota = Quota::allTenants()->find((string) $quotaId);

            if ($quota === null) {
                return response()->json(['error' => 'Quota not found'], 404);
            }

            $headers = [
                'paypal-auth-algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'paypal-cert-url' => $request->header('PAYPAL-CERT-URL'),
                'paypal-transmission-id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'paypal-transmission-sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'paypal-transmission-time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            ];

            $ok = $paymentService->handlePayPalWebhook($event, $headers, $quota->associazione_id);

            return $ok
                ? response()->json(['status' => 'ok'])
                : response()->json(['error' => 'Webhook processing failed'], 400);
        } catch (\Throwable $e) {
            Log::error('PayPalWebhookController', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Internal error'], 500);
        }
    }
}
