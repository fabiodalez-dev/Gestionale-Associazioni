<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\ApiV1\AssociazioneListItemData;
use App\Http\Controllers\Api\V1\Concerns\ApiV1Responses;
use App\Http\Controllers\Controller;
use App\Models\Associazione;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REST API v1 — associazioni. Disponibile SOLO per client globali
 * (chiavi super_admin senza associazione / token di super_admin).
 */
class AssociazioneApiController extends Controller
{
    use ApiV1Responses;

    /**
     * GET /api/v1/associazioni — elenco associazioni attive.
     */
    public function index(Request $request): JsonResponse
    {
        $client = $this->apiClient($request);

        if (! $client->isGlobale()) {
            return $this->apiError(403, 'global_only', 'Endpoint disponibile solo per le chiavi API globali.');
        }

        $associazioni = Associazione::query()
            ->where('attiva', true)
            ->orderBy('nome')
            ->get();

        return response()->json([
            'success' => true,
            'count' => $associazioni->count(),
            'associazioni' => $associazioni
                ->map(fn (Associazione $a) => AssociazioneListItemData::fromAssociazione($a))
                ->values(),
        ]);
    }
}
