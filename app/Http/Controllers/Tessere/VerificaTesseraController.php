<?php

namespace App\Http\Controllers\Tessere;

use App\Http\Controllers\Controller;
use App\Models\Tessera;
use App\Services\Tessere\TesseraQrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Verifica pubblica della tessera via QR (spec §8.1).
 *
 * Nessuna autenticazione: la capability è la firma (APP_KEY) dell'URL
 * stampato nel QR, obbligatoria. Il solo UUID non basta: senza `signature`
 * valida la risposta è la stessa di una tessera inesistente (not_found),
 * decisa prima della lookup, così non rivela se l'UUID esiste.
 * Stati: no_param | not_found | valida | scaduta.
 */
class VerificaTesseraController extends Controller
{
    private const UUID_REGEX = '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i';

    public function __construct(private readonly TesseraQrService $qr) {}

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

        // Firma mancante o manomessa → not_found, prima di toccare il DB. La
        // firma è quella dell'URL di verifica (stessa per la pagina HTML e per
        // l'endpoint JSON dello scanner, che la ricava dal QR scansionato)
        if (! $this->qr->firmaValida($tesseraId, $request->query('signature'))) {
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
