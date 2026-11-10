<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiV1Responses;
use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Services\Update\UpdateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Stato dell'API per le app esterne (#75), come /health della Mobile API di
 * Pinakes: chi integra sa subito con che versione parla e cosa può fare,
 * senza tentativi a vuoto.
 */
class HealthApiController extends Controller
{
    use ApiV1Responses;

    /**
     * Stato dell'API.
     *
     * Pubblico e senza dati sensibili: versione dell'app e dell'API.
     *
     * @unauthenticated
     */
    public function show(UpdateService $update): JsonResponse
    {
        return response()->json($this->base($update));
    }

    /**
     * Stato dell'API e della credenziale.
     *
     * Con una chiave API o un token Sanctum: in più il tipo di credenziale,
     * i permessi effettivi e l'associazione a cui è vincolata (null = globale).
     */
    public function credenziale(Request $request, UpdateService $update): JsonResponse
    {
        $client = $this->apiClient($request);
        $associazione = $client->associazioneId !== null
            ? Associazione::query()->whereKey($client->associazioneId)->first(['id', 'nome', 'slug'])
            : null;

        return response()->json($this->base($update) + [
            'credenziale' => [
                'tipo' => $client->tipo,
                'permessi' => $client->permessi,
                'globale' => $client->isGlobale(),
                'associazione' => $associazione === null ? null : [
                    'id' => $associazione->id,
                    'nome' => $associazione->nome,
                    'slug' => $associazione->slug,
                ],
            ],
        ]);
    }

    /** @return array{success: true, stato: string, versione_app: string, versione_api: string, ora: string} */
    private function base(UpdateService $update): array
    {
        return [
            'success' => true,
            'stato' => 'ok',
            'versione_app' => $update->versioneCorrente(),
            'versione_api' => 'v1',
            'ora' => now()->toIso8601String(),
        ];
    }
}
