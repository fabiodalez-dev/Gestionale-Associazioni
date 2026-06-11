<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\ApiV1\SocioListItemData;
use App\Http\Controllers\Api\V1\Concerns\ApiV1Responses;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiV1\UpdateSocioApiRequest;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Spatie\QueryBuilder\Exceptions\InvalidFilterQuery;
use Spatie\QueryBuilder\Exceptions\InvalidSortQuery;
use Spatie\QueryBuilder\QueryBuilder;

/**
 * REST API v1 — soci (permessi soci:read / soci:write).
 */
class SocioApiController extends Controller
{
    use ApiV1Responses;

    /**
     * GET /api/v1/soci/search?q={query} — ricerca (min 2 caratteri, max 50 risultati).
     */
    public function search(Request $request): JsonResponse
    {
        $client = $this->apiClient($request);
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return $this->apiError(400, 'missing_query', 'Parametro q mancante o troppo corto (minimo 2 caratteri).');
        }

        $filtroAssoc = $client->filtroAssociazione($request->query('associazione_id'));
        $like = '%'.$q.'%';

        $soci = Socio::allTenants()
            ->when($client->isGlobale(), fn (Builder $query) => $query->with('associazione:id,nome'))
            ->when($filtroAssoc, fn (Builder $query) => $query->where('soci.associazione_id', $filtroAssoc))
            ->where(fn (Builder $query) => $query
                ->where('cognome', 'like', $like)
                ->orWhere('nome', 'like', $like)
                ->orWhere('numero_socio', 'like', $like)
                ->orWhere('email', 'like', $like)
                ->orWhere('codice_fiscale', 'like', $like))
            ->orderBy('cognome')
            ->orderBy('nome')
            ->limit(50)
            ->get();

        return response()->json([
            'success' => true,
            'count' => $soci->count(),
            'soci' => $soci
                ->map(fn (Socio $s) => SocioListItemData::fromSocio($s, $client->isGlobale()))
                ->values(),
        ]);
    }

    /**
     * GET /api/v1/soci/{id} — dettaglio con tags e ultime 5 tessere.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $client = $this->apiClient($request);

        if (! $this->isUuidLike($id)) {
            return $this->apiError(400, 'invalid_id', 'ID socio non valido.');
        }

        $socio = $this->findSocio($client->isGlobale(), $client->associazioneId, $id);

        if (! $socio) {
            return $this->apiError(404, 'not_found', 'Socio non trovato.');
        }

        $payload = [
            'id' => $socio->id,
            'numero_socio' => $socio->numero_socio,
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'email' => $socio->email,
            'telefono' => $socio->telefono,
            'data_nascita' => $socio->data_nascita?->format('Y-m-d'),
            'codice_fiscale' => $socio->codice_fiscale,
            'indirizzo' => $socio->indirizzo,
            'citta' => $socio->citta,
            'provincia' => $socio->provincia,
            'cap' => $socio->cap,
            'data_iscrizione' => $socio->data_iscrizione->format('Y-m-d'),
            'stato' => $socio->stato,
            'note' => $socio->note,
            'tipo_socio' => $socio->tipoSocio?->nome,
            'categoria_socio' => $socio->categoriaSocio?->nome,
            // Fix rispetto al legacy: i tag sono comunque del socio (pivot socio_id),
            // l'isolamento tenant è garantito dalla lookup scopata del socio stesso.
            'tags' => $socio->tags
                ->map(fn ($tag) => ['nome_tag' => $tag->nome_tag, 'colore' => $tag->colore])
                ->values(),
            'tessere' => $socio->tessere()
                ->withoutGlobalScope('associazione')
                ->where('associazione_id', $socio->associazione_id)
                ->orderByDesc('anno_validita')
                ->limit(5)
                ->get()
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'numero_tessera' => $t->numero_tessera,
                    'anno_validita' => $t->anno_validita,
                    'data_scadenza' => $t->data_scadenza->format('Y-m-d'),
                    'stato' => $t->stato,
                ])
                ->values(),
        ];

        if ($client->isGlobale()) {
            $payload['associazione_id'] = $socio->associazione_id;
            $payload['associazione_nome'] = $socio->associazione?->nome;
        }

        return response()->json(['success' => true, 'socio' => $payload]);
    }

    /**
     * PUT /api/v1/soci/{id} — aggiornamento parziale (whitelist campi, permesso soci:write).
     */
    public function update(UpdateSocioApiRequest $request, string $id): JsonResponse
    {
        $client = $this->apiClient($request);

        if (! $this->isUuidLike($id)) {
            return $this->apiError(400, 'invalid_id', 'ID socio non valido.');
        }

        $socio = $this->findSocio($client->isGlobale(), $client->associazioneId, $id);

        if (! $socio) {
            return $this->apiError(404, 'not_found', 'Socio non trovato.');
        }

        $body = $request->validationData();

        // Whitelist: i campi non previsti vengono ignorati in silenzio (parità legacy)
        $aggiornamenti = [];

        foreach (UpdateSocioApiRequest::CAMPI_AGGIORNABILI as $campo) {
            if (array_key_exists($campo, $body)) {
                $valore = $body[$campo];
                $aggiornamenti[$campo] = is_string($valore) ? $this->cleanInput($valore) : $valore;
            }
        }

        if ($aggiornamenti === []) {
            return $this->apiError(400, 'no_valid_fields', 'Nessun campo aggiornabile presente nel body.');
        }

        try {
            Socio::allTenants()
                ->whereKey($socio->id)
                ->when(! $client->isGlobale(), fn (Builder $q) => $q->where('associazione_id', $client->associazioneId))
                ->update($aggiornamenti);
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'Duplicate entry') || str_contains($e->getMessage(), 'UNIQUE constraint failed')) {
                return $this->apiError(409, 'duplicate_email', 'Email già presente per un altro socio.');
            }

            Log::error('API v1 — errore aggiornamento socio', ['socio_id' => $socio->id, 'exception' => $e->getMessage()]);

            return $this->apiError(500, 'db_error', 'Errore database. Riprova più tardi.');
        }

        return response()->json(['success' => true, 'message' => 'Socio aggiornato.']);
    }

    /**
     * GET /api/v1/soci — lista paginata.
     *
     * Filtro legacy: stato (whitelist). In aggiunta (miglioramento 24):
     * filter[stato], filter[citta], filter[cognome] e sort (cognome, nome,
     * numero_socio, data_iscrizione).
     */
    public function index(Request $request): JsonResponse
    {
        $client = $this->apiClient($request);
        ['page' => $page, 'limit' => $limit] = $this->paginazione($request);

        $filtroAssoc = $client->filtroAssociazione($request->query('associazione_id'));

        try {
            $query = QueryBuilder::for(
                Socio::allTenants()
                    ->when($filtroAssoc, fn (Builder $q) => $q->where('soci.associazione_id', $filtroAssoc)),
                $request
            )
                ->allowedFilters(...[
                    AllowedFilter::exact('stato'),
                    AllowedFilter::partial('citta'),
                    AllowedFilter::partial('cognome'),
                ])
                ->allowedSorts(...[
                    AllowedSort::field('cognome'),
                    AllowedSort::field('nome'),
                    AllowedSort::field('numero_socio'),
                    AllowedSort::field('data_iscrizione'),
                ])
                ->getEloquentBuilder();
        } catch (InvalidFilterQuery|InvalidSortQuery $e) {
            return $this->apiError(400, 'invalid_filter', $e->getMessage());
        }

        $stato = $request->query('stato');
        if (is_string($stato) && in_array($stato, UpdateSocioApiRequest::STATI, true)) {
            $query->where('stato', $stato);
        }

        $total = (clone $query)->count();

        $soci = $query
            ->when($client->isGlobale(), fn (Builder $q) => $q->with('associazione:id,nome'))
            ->when(! $request->filled('sort'), fn (Builder $q) => $q->orderBy('cognome')->orderBy('nome'))
            ->forPage($page, $limit)
            ->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'soci' => $soci
                ->map(fn (Socio $s) => SocioListItemData::fromSocio($s, $client->isGlobale()))
                ->values(),
        ]);
    }

    private function findSocio(bool $globale, ?string $associazioneId, string $id): ?Socio
    {
        return Socio::allTenants()
            ->where('id', $id)
            ->when(! $globale, fn (Builder $q) => $q->where('associazione_id', $associazioneId))
            ->first();
    }

    /**
     * Equivalente di cleanInput() legacy: rimuove caratteri di controllo e trimma.
     */
    private function cleanInput(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
    }
}
