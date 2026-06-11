<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\ApiV1\QuotaData;
use App\Http\Controllers\Api\V1\Concerns\ApiV1Responses;
use App\Http\Controllers\Controller;
use App\Models\Quota;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * REST API v1 — quote (sola lettura, permesso quote:read).
 */
class QuotaApiController extends Controller
{
    use ApiV1Responses;

    /** Stati filtro ammessi (parità legacy). */
    private const STATI = [Quota::STATO_PAGATA, Quota::STATO_DA_PAGARE, Quota::STATO_SCADUTA, Quota::STATO_IN_SCADENZA];

    /**
     * GET /api/v1/quote/{socio_id} — tutte le quote di un socio (senza paginazione).
     */
    public function perSocio(Request $request, string $socioId): JsonResponse
    {
        $client = $this->apiClient($request);

        if (! $this->isUuidLike($socioId)) {
            return $this->apiError(400, 'invalid_id', 'ID socio non valido.');
        }

        /** @var Socio|null $socio */
        $socio = Socio::allTenants()
            ->where('id', $socioId)
            ->when(! $client->isGlobale(), fn (Builder $q) => $q->where('associazione_id', $client->associazioneId))
            ->first();

        if (! $socio) {
            return $this->apiError(404, 'socio_not_found', 'Socio non trovato.');
        }

        // Le quote sono filtrate per socio + associazione DEL SOCIO
        // (corretto anche per i client globali).
        $quote = Quota::allTenants()
            ->where('socio_id', $socio->id)
            ->where('associazione_id', $socio->associazione_id)
            ->orderByDesc('anno')
            ->get();

        $socioPayload = [
            'id' => $socio->id,
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'numero_socio' => $socio->numero_socio,
        ];

        if ($client->isGlobale()) {
            $socioPayload['associazione_id'] = $socio->associazione_id;
        }

        return response()->json([
            'success' => true,
            'socio' => $socioPayload,
            'quote' => $quote->map(fn (Quota $q) => QuotaData::fromQuota($q))->values(),
        ]);
    }

    /**
     * GET /api/v1/quote — lista paginata con dati socio.
     *
     * Filtri legacy: anno, stato (whitelist). In aggiunta (miglioramento 24):
     * filter[anno], filter[stato], filter[tipo] e sort (anno, importo, data_scadenza).
     */
    public function index(Request $request): JsonResponse
    {
        $client = $this->apiClient($request);
        ['page' => $page, 'limit' => $limit] = $this->paginazione($request);

        $filtroAssoc = $client->filtroAssociazione($request->query('associazione_id'));

        try {
            $query = QueryBuilder::for(
                Quota::allTenants()
                    ->when($filtroAssoc, fn (Builder $q) => $q->where('quote.associazione_id', $filtroAssoc)),
                $request
            )
                ->allowedFilters(...[
                    AllowedFilter::exact('anno'),
                    AllowedFilter::exact('stato'),
                    AllowedFilter::partial('tipo'),
                ])
                ->allowedSorts(...[
                    AllowedSort::field('anno'),
                    AllowedSort::field('importo'),
                    AllowedSort::field('data_scadenza'),
                ])
                ->getEloquentBuilder();
        } catch (InvalidFilterQuery|InvalidSortQuery $e) {
            return $this->apiError(400, 'invalid_filter', $e->getMessage());
        }

        if (is_numeric($request->query('anno'))) {
            $query->where('anno', (int) $request->query('anno'));
        }

        $stato = $request->query('stato');
        if (is_string($stato) && in_array($stato, self::STATI, true)) {
            $query->where('stato', $stato);
        }

        $total = (clone $query)->count();

        $quote = $query
            ->with(array_filter([
                'socio' => fn ($q) => $q->withoutGlobalScope('associazione'),
                $client->isGlobale() ? 'associazione:id,nome' : null,
            ]))
            ->when(
                ! $request->filled('sort'),
                fn (Builder $q) => $q
                    ->orderByDesc('anno')
                    ->orderBy(
                        Socio::withoutGlobalScopes()->select('cognome')->whereColumn('soci.id', 'quote.socio_id')
                    )
                    ->orderBy(
                        Socio::withoutGlobalScopes()->select('nome')->whereColumn('soci.id', 'quote.socio_id')
                    )
            )
            ->forPage($page, $limit)
            ->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'quote' => $quote
                ->map(fn (Quota $q) => QuotaData::fromQuota($q, conSocio: true, globale: $client->isGlobale()))
                ->values(),
        ]);
    }
}
