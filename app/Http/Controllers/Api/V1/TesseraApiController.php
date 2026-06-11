<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\ApiV1\TesseraListItemData;
use App\Http\Controllers\Api\V1\Concerns\ApiV1Responses;
use App\Http\Controllers\Controller;
use App\Models\Tessera;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * REST API v1 — tessere (sola lettura, permesso tessere:read).
 *
 * /tessere/verify è PUBBLICO (nessuna autenticazione): l'UUID della tessera,
 * stampato nel QR code, è esso stesso il segreto. La lookup è volutamente
 * cross-tenant e la risposta non espone associazione_id né dati sensibili.
 */
class TesseraApiController extends Controller
{
    use ApiV1Responses;

    /**
     * GET /api/v1/tessere/verify?tessera_id={uuid} — verifica pubblica (QR code).
     *
     * @unauthenticated
     */
    public function verify(Request $request): JsonResponse
    {
        $tesseraId = (string) $request->query('tessera_id', '');

        if ($tesseraId === '' || ! $this->isUuid($tesseraId)) {
            return $this->apiError(400, 'invalid_tessera_id', 'Parametro tessera_id mancante o non valido (UUID richiesto).');
        }

        /** @var Tessera|null $tessera */
        $tessera = Tessera::allTenants()
            ->with(['socio' => fn ($q) => $q->withoutGlobalScope('associazione'), 'associazione:id,nome'])
            ->find(strtolower($tesseraId));

        if (! $tessera) {
            return $this->apiError(404, 'tessera_not_found', 'Tessera non trovata.');
        }

        $valida = $tessera->stato === Tessera::STATO_ATTIVA
            && $tessera->data_scadenza->gte(now()->startOfDay());

        return response()->json([
            'success' => true,
            'valid' => $valida,
            'tessera' => [
                'numero_tessera' => $tessera->numero_tessera,
                'anno_validita' => $tessera->anno_validita,
                'data_emissione' => $tessera->data_emissione->format('Y-m-d'),
                'data_scadenza' => $tessera->data_scadenza->format('Y-m-d'),
                'stato' => $tessera->stato,
            ],
            'socio' => [
                'nome' => $tessera->socio?->nome,
                'cognome' => $tessera->socio?->cognome,
                'numero_socio' => $tessera->socio?->numero_socio,
            ],
            'associazione' => $tessera->associazione?->nome,
        ]);
    }

    /**
     * GET /api/v1/tessere/search?q={query} — ricerca (max 50 risultati).
     */
    public function search(Request $request): JsonResponse
    {
        $client = $this->apiClient($request);
        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return $this->apiError(400, 'missing_query', 'Parametro q mancante (minimo 1 carattere).');
        }

        $filtroAssoc = $client->filtroAssociazione($request->query('associazione_id'));

        $tessere = Tessera::allTenants()
            ->with($this->relazioni($client->isGlobale()))
            ->when($filtroAssoc, fn (Builder $query) => $query->where('tessere.associazione_id', $filtroAssoc))
            ->where(function (Builder $query) use ($q) {
                $like = '%'.$q.'%';
                $query->where('tessere.numero_tessera', 'like', $like)
                    ->orWhereHas('socio', function (Builder $socio) use ($like) {
                        $socio->withoutGlobalScope('associazione')
                            ->where(fn (Builder $s) => $s
                                ->where('cognome', 'like', $like)
                                ->orWhere('nome', 'like', $like)
                                ->orWhere('numero_socio', 'like', $like));
                    });
            })
            ->orderByDesc('anno_validita')
            ->orderBy('numero_tessera')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'count' => $tessere->count(),
            'tessere' => $tessere
                ->map(fn (Tessera $t) => TesseraListItemData::fromTessera($t, $client->isGlobale()))
                ->values(),
        ]);
    }

    /**
     * GET /api/v1/tessere/{id} — dettaglio tessera.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $client = $this->apiClient($request);

        if (! $this->isUuidLike($id)) {
            return $this->apiError(400, 'invalid_id', 'ID tessera non valido.');
        }

        /** @var Tessera|null $tessera */
        $tessera = Tessera::allTenants()
            ->with($this->relazioni($client->isGlobale()))
            ->where('id', $id)
            // Risorsa di un altro tenant → 404, mai 403 (non rivela l'esistenza)
            ->when(! $client->isGlobale(), fn (Builder $q) => $q->where('associazione_id', $client->associazioneId))
            ->first();

        if (! $tessera) {
            return $this->apiError(404, 'not_found', 'Tessera non trovata.');
        }

        $payload = $tessera->attributesToArray();
        $payload['data_emissione'] = $tessera->data_emissione->format('Y-m-d');
        $payload['data_scadenza'] = $tessera->data_scadenza->format('Y-m-d');
        $payload['socio_nome'] = $tessera->socio?->nome;
        $payload['socio_cognome'] = $tessera->socio?->cognome;
        $payload['numero_socio'] = $tessera->socio?->numero_socio;

        if ($client->isGlobale()) {
            $payload['associazione_nome'] = $tessera->associazione?->nome;
        }

        return response()->json(['success' => true, 'tessera' => $payload]);
    }

    /**
     * GET /api/v1/tessere — lista paginata.
     *
     * Filtri legacy: anno, stato (whitelist). In aggiunta (miglioramento 24,
     * spatie/query-builder): filter[anno], filter[stato], filter[numero_tessera]
     * e sort (anno_validita, numero_tessera, data_scadenza).
     */
    public function index(Request $request): JsonResponse
    {
        $client = $this->apiClient($request);
        ['page' => $page, 'limit' => $limit] = $this->paginazione($request);

        $filtroAssoc = $client->filtroAssociazione($request->query('associazione_id'));

        try {
            $query = QueryBuilder::for(
                Tessera::allTenants()
                    ->when($filtroAssoc, fn (Builder $q) => $q->where('tessere.associazione_id', $filtroAssoc)),
                $request
            )
                ->allowedFilters(...[
                    AllowedFilter::exact('anno', 'anno_validita'),
                    AllowedFilter::exact('stato'),
                    AllowedFilter::partial('numero_tessera'),
                ])
                ->allowedSorts(...[
                    AllowedSort::field('anno_validita'),
                    AllowedSort::field('numero_tessera'),
                    AllowedSort::field('data_scadenza'),
                ])
                ->getEloquentBuilder();
        } catch (InvalidFilterQuery|InvalidSortQuery $e) {
            return $this->apiError(400, 'invalid_filter', $e->getMessage());
        }

        // Parametri legacy (parità con i client esistenti)
        if (is_numeric($request->query('anno'))) {
            $query->where('anno_validita', (int) $request->query('anno'));
        }

        $stato = $request->query('stato');
        if (is_string($stato) && in_array($stato, Tessera::STATI, true)) {
            $query->where('stato', $stato);
        }

        $total = (clone $query)->count();

        $tessere = $query
            ->with($this->relazioni($client->isGlobale()))
            ->when(! $request->filled('sort'), fn (Builder $q) => $q->orderByDesc('anno_validita')->orderBy('numero_tessera'))
            ->forPage($page, $limit)
            ->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'tessere' => $tessere
                ->map(fn (Tessera $t) => TesseraListItemData::fromTessera($t, $client->isGlobale()))
                ->values(),
        ]);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function relazioni(bool $globale): array
    {
        $relazioni = ['socio' => fn ($q) => $q->withoutGlobalScope('associazione')];

        if ($globale) {
            $relazioni[] = 'associazione:id,nome';
        }

        return $relazioni;
    }
}
