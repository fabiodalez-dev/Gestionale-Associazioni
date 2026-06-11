<?php

namespace App\Http\Controllers\Quote\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Stripe (ex api/webhooks/stripe.php).
 * Pubblico, senza CSRF; sicurezza = verifica firma con secret per-tenant
 * (il tenant è ricavato da data.object.metadata.associazione_id).
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, PaymentService $paymentService): JsonResponse
    {
        try {
            $payload = $request->getContent();
            $signature = (string) $request->header('Stripe-Signature', '');

            if ($payload === '' || $signature === '') {
                return response()->json(['error' => 'Missing payload or signature'], 400);
            }

            $event = json_decode($payload, true);

            if (! is_array($event)) {
                return response()->json(['error' => 'Invalid JSON'], 400);
            }

            $associazioneId = data_get($event, 'data.object.metadata.associazione_id');

            if (blank($associazioneId)) {
                // Eventi non-checkout: ack per non far ritentare Stripe
                return response()->json(['status' => 'ignored', 'reason' => 'no associazione_id in metadata']);
            }

            if (! preg_match('/^[a-f0-9\-]{36}$/i', (string) $associazioneId)) {
                return response()->json(['error' => 'Invalid associazione_id'], 400);
            }

            $ok = $paymentService->handleStripeWebhook($payload, $signature, (string) $associazioneId);

            return $ok
                ? response()->json(['status' => 'ok'])
                : response()->json(['error' => 'Webhook processing failed'], 400);
        } catch (\Throwable $e) {
            Log::error('StripeWebhookController', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Internal error'], 500);
        }
    }
}
