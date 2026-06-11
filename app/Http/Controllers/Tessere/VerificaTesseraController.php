<?php

namespace App\Http\Controllers\Tessere;

use App\Http\Controllers\Controller;
use App\Models\Tessera;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Verifica pubblica della tessera via QR (spec §8.1).
 *
 * Nessuna autenticazione: l'UUID della tessera funge da capability token.
 * I QR generati dall'app contengono un URL firmato (APP_KEY): se la firma
 * è presente ma non valida la tessera è trattata come non trovata.
 * Stati: no_param | not_found | valida | scaduta.
 */
class VerificaTesseraController extends Controller
{
    private const UUID_REGEX = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    /**
     * Pagina HTML pubblica (anche JSON con ?json=1 per compatibilità legacy).
     */
    public function show(Request $request, ?string $tessera = null): View|JsonResponse
    {
        // Compat legacy: ?t={uuid}
        $tesseraId = $tessera ?? (string) $request->query('t', '');

        [$stato, $model] = $this->verifica($request, $tesseraId);

        if ($request->has('json')) {
            return $this->jsonResponse($stato, $model);
        }

        return view('tessere.verifica', [
            'stato' => $stato,
            'tessera' => $model,
        ]);
    }

    /**
     * Endpoint JSON usato dallo scanner.
     */
    public function json(Request $request, string $tessera): JsonResponse
    {
        [$stato, $model] = $this->verifica($request, $tessera);

        return $this->jsonResponse($stato, $model);
    }

    /**
     * @return array{0: string, 1: Tessera|null}
     */
    private function verifica(Request $request, string $tesseraId): array
    {
        if ($tesseraId === '') {
            return ['no_param', null];
        }

        if (! preg_match(self::UUID_REGEX, $tesseraId)) {
            return ['not_found', null];
        }

        // Firma presente ma manomessa → not_found (il solo UUID resta valido:
        // i QR già stampati e l'inserimento manuale continuano a funzionare)
        if ($request->has('signature') && ! $request->hasValidSignatureWhileIgnoring(['json', 't'])) {
            return ['not_found', null];
        }

        try {
            // La verifica è cross-tenant per design: si bypassa il global scope
            // (anche quello del socio, nel caso un utente loggato di un'altra
            // associazione scansioni la tessera).
            /** @var Tessera|null $tessera */
            $tessera = Tessera::allTenants()
                ->whereKey(strtolower($tesseraId))
                ->whereHas('socio', fn ($q) => $q->withoutGlobalScope('associazione'))
                ->with([
                    'socio' => fn ($q) => $q->withoutGlobalScope('associazione'),
                    'associazione',
                ])
                ->first();
        } catch (\Throwable $e) {
            report($e);

            return ['not_found', null];
        }

        if ($tessera === null) {
            return ['not_found', null];
        }

        return [$tessera->isValida() ? 'valida' : 'scaduta', $tessera];
    }

    private function jsonResponse(string $stato, ?Tessera $tessera): JsonResponse
    {
        $payload = ['stato' => $stato];

        if ($tessera !== null) {
            $payload['tessera'] = [
                'numero_tessera' => $tessera->numero_tessera,
                'anno_validita' => $tessera->anno_validita,
                'data_scadenza' => $tessera->data_scadenza->toDateString(),
                'stato_tessera' => $tessera->stato,
                'socio_nome' => $tessera->socio?->nome,
                'socio_cognome' => $tessera->socio?->cognome,
                'associazione_nome' => $tessera->associazione?->nome,
            ];
        }

        return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE);
    }
}
