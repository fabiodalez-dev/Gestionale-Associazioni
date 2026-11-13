<?php

namespace App\Http\Controllers\Quote\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Donazione;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Services\Pagamenti\EsitoWebhook;
use App\Services\Pagamenti\Pagabile;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook PayPal (ex api/webhooks/paypal.php).
 * Pubblico, senza CSRF; il tenant è risalito dall'entità nel custom_id
 * (UUID nudo = quota per retrocompatibilità, "prenotazione:{uuid}" =
 * prenotazione, "donazione:{uuid}" = donazione); il custom_id dell'evento
 * serve SOLO a individuare il tenant: sicurezza = verifica firma con il
 * webhook id del tenant + capture server-side, da cui vengono letti
 * custom_id e importo effettivi.
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

            $customId = data_get($event, 'resource.purchase_units.0.custom_id');

            if (blank($customId)) {
                // Eventi non-order: ack per non far ritentare PayPal
                return response()->json(['status' => 'ignored', 'reason' => 'no custom_id in resource']);
            }

            $associazioneId = $this->resolveAssociazioneId((string) $customId);

            if ($associazioneId instanceof JsonResponse) {
                return $associazioneId;
            }

            $headers = [
                'paypal-auth-algo' => $request->header('PAYPAL-AUTH-ALGO'),
                'paypal-cert-url' => $request->header('PAYPAL-CERT-URL'),
                'paypal-transmission-id' => $request->header('PAYPAL-TRANSMISSION-ID'),
                'paypal-transmission-sig' => $request->header('PAYPAL-TRANSMISSION-SIG'),
                'paypal-transmission-time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            ];

            $esito = $paymentService->handlePayPalWebhook($event, $headers, $associazioneId);

            return match ($esito) {
                EsitoWebhook::OK => response()->json(['status' => 'ok']),
                EsitoWebhook::IGNORATO => response()->json(['status' => 'ignored', 'reason' => 'capture inconsistent with payable']),
                EsitoWebhook::FALLITO => response()->json(['error' => 'Webhook processing failed'], 400),
            };
        } catch (\Throwable $e) {
            Log::error('PayPalWebhookController', ['error' => $e->getMessage()]);

            return response()->json(['error' => 'Internal error'], 500);
        }
    }

    /**
     * Risale al tenant dall'entità indicata nel custom_id.
     * Restituisce l'id associazione, oppure la JsonResponse d'errore.
     */
    protected function resolveAssociazioneId(string $customId): string|JsonResponse
    {
        $modelli = [
            Pagabile::TIPO_PRENOTAZIONE => Prenotazione::class,
            Pagabile::TIPO_DONAZIONE => Donazione::class,
        ];

        foreach ($modelli as $tipo => $modello) {
            $prefisso = $tipo.':';

            if (! str_starts_with($customId, $prefisso)) {
                continue;
            }

            $id = substr($customId, strlen($prefisso));

            if (! preg_match('/^[a-f0-9\-]{36}$/i', $id)) {
                return response()->json(['error' => "Invalid {$tipo} id"], 400);
            }

            /** @var Model|null $entita */
            $entita = $modello::allTenants()->find($id);

            if ($entita === null) {
                return response()->json(['error' => ucfirst($tipo).' not found'], 404);
            }

            return (string) $entita->getAttribute('associazione_id');
        }

        // UUID nudo = quota (retrocompatibilità con gli ordini legacy in volo)
        if (! preg_match('/^[a-f0-9\-]{36}$/i', $customId)) {
            return response()->json(['error' => 'Invalid quota id'], 400);
        }

        /** @var Quota|null $quota */
        $quota = Quota::allTenants()->find($customId);

        if ($quota === null) {
            return response()->json(['error' => 'Quota not found'], 404);
        }

        return $quota->associazione_id;
    }
}
