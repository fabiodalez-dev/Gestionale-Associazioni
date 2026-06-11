<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REST API v1 — modulo eventi (consumata dalla pagina mobile di check-in).
 *
 * Autenticazione: middleware AuthenticateApiKey (Bearer).
 * - Chiave scoped → solo eventi del tenant della chiave.
 * - Chiave globale (super_admin) → tutti i tenant; per il check-in il tenant
 *   è SEMPRE derivato dall'evento, non dalla chiave.
 *
 * Gli scope globali Eloquent vengono bypassati (allTenants): lo scoping è
 * esplicito e deterministico, indipendente da sessioni web eventualmente attive.
 */
class EventoApiController extends Controller
{
    /**
     * GET /api/v1/ — verifica della chiave API (usata dal setup della pagina check-in).
     */
    public function ping(Request $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        return response()->json([
            'success' => true,
            'message' => 'API key valida.',
            'api_version' => 'v1',
            'globale' => $apiKey->isGlobale(),
            'associazione' => $apiKey->isGlobale() ? null : $apiKey->associazione?->nome,
        ]);
    }

    /**
     * GET /api/v1/eventi — lista paginata (permesso eventi:read).
     * Filtri: futuri=1, oggi=1; page, limit (max 100, default 20).
     */
    public function index(Request $request): JsonResponse
    {
        /** @var ApiKey $apiKey */
        $apiKey = $request->attributes->get('api_key');

        $page = max(1, (int) $request->query('page', '1'));
        $limit = min(100, max(1, (int) $request->query('limit', '20')));

        $query = Evento::allTenants()
            ->when(! $apiKey->isGlobale(), fn ($q) => $q->where('associazione_id', $apiKey->associazione_id))
            ->when($request->query('futuri') === '1', fn ($q) => $q->where('data_evento', '>=', now()))
            ->when($request->query('oggi') === '1', fn ($q) => $q->whereDate('data_evento', now()->toDateString()));

        $total = (clone $query)->count();

        $eventi = $query
            ->when($apiKey->isGlobale(), fn ($q) => $q->with('associazione:id,nome'))
            ->orderByDesc('data_evento')
            ->forPage($page, $limit)
            ->get();

        return response()->json([
            'success' => true,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'eventi' => $eventi->map(function (Evento $evento) use ($apiKey) {
                $row = [
                    'id' => $evento->id,
                    'titolo' => $evento->titolo,
                    'descrizione' => $evento->descrizione,
                    'data_evento' => $evento->data_evento->format('Y-m-d H:i:s'),
                    'luogo' => $evento->luogo,
                ];

                if ($apiKey->isGlobale()) {
                    $row['associazione_id'] = $evento->associazione_id;
                    $row['associazione_nome'] = $evento->associazione?->nome;
                }

                return $row;
            })->values(),
        ]);
    }

    /**
     * GET /api/v1/eventi/{id} — dettaglio evento con partecipanti (permesso eventi:read).
     */
    public function show(Request $request, string $id): JsonResponse
    {
        if (! $this->isValidUuid($id)) {
            return $this->error(400, 'invalid_id', 'ID evento non valido.');
        }

        $evento = $this->findEvento($request->attributes->get('api_key'), $id);

        if (! $evento) {
            return $this->error(404, 'not_found', 'Evento non trovato.');
        }

        $partecipanti = EventoPartecipante::allTenants()
            ->where('evento_id', $evento->id)
            ->join('soci', 'soci.id', '=', 'eventi_partecipanti.socio_id')
            ->orderBy('soci.cognome')
            ->orderBy('soci.nome')
            ->get([
                'eventi_partecipanti.stato_partecipazione',
                'eventi_partecipanti.data_conferma',
                'eventi_partecipanti.socio_id',
                'soci.nome',
                'soci.cognome',
                'soci.numero_socio',
            ]);

        return response()->json([
            'success' => true,
            'evento' => [
                'id' => $evento->id,
                'titolo' => $evento->titolo,
                'descrizione' => $evento->descrizione,
                'data_evento' => $evento->data_evento->format('Y-m-d H:i:s'),
                'luogo' => $evento->luogo,
            ],
            'partecipanti' => $partecipanti->map(fn (EventoPartecipante $p) => [
                'stato_partecipazione' => $p->stato_partecipazione,
                'data_conferma' => $p->data_conferma?->format('Y-m-d H:i:s'),
                'socio_id' => $p->socio_id,
                // nome/cognome/numero_socio arrivano dalla join su soci:
                // attributi dinamici, non colonne di eventi_partecipanti.
                'nome' => $p->getAttribute('nome'),
                'cognome' => $p->getAttribute('cognome'),
                'numero_socio' => $p->getAttribute('numero_socio'),
            ])->values(),
            'totale_partecipanti' => $partecipanti->count(),
        ]);
    }

    /**
     * POST /api/v1/eventi/{id}/checkin — check-in di un socio (permesso eventi:write).
     *
     * Il check-in È la transizione a "Confermato": idempotente se il socio
     * risulta già Confermato (nessuna modifica, conserva la data_conferma
     * del primo ingresso).
     */
    public function checkin(Request $request, string $id): JsonResponse
    {
        if (! $this->isValidUuid($id)) {
            return $this->error(400, 'invalid_id', 'ID evento non valido.');
        }

        $evento = $this->findEvento($request->attributes->get('api_key'), $id);

        if (! $evento) {
            return $this->error(404, 'not_found', 'Evento non trovato.');
        }

        // Da qui in poi il tenant è SEMPRE quello dell'evento
        // (rilevante per le chiavi globali).
        $associazioneId = $evento->associazione_id;

        $socioId = $this->risolviSocioId($request, $associazioneId);

        if ($socioId === null) {
            return $this->error(
                400,
                'missing_socio',
                'Socio non identificato. Fornisci socio_id, tessera_id, numero_tessera o numero_socio.'
            );
        }

        /** @var Socio|null $socio */
        $socio = Socio::allTenants()
            ->where('id', $socioId)
            ->where('associazione_id', $associazioneId)
            ->first();

        if (! $socio) {
            return $this->error(404, 'socio_not_found', 'Socio non trovato.');
        }

        if ($socio->stato !== Socio::STATO_ATTIVO) {
            return $this->error(
                400,
                'socio_not_active',
                "Il socio non è attivo (stato attuale: {$socio->stato}). Check-in rifiutato."
            );
        }

        try {
            $result = DB::transaction(function () use ($evento, $socio, $associazioneId) {
                /** @var EventoPartecipante|null $partecipazione */
                $partecipazione = EventoPartecipante::allTenants()
                    ->where('evento_id', $evento->id)
                    ->where('socio_id', $socio->id)
                    ->lockForUpdate()
                    ->first();

                if ($partecipazione && $partecipazione->stato_partecipazione === EventoPartecipante::STATO_CONFERMATO) {
                    return ['already_checked_in' => true];
                }

                if ($partecipazione) {
                    $partecipazione->update([
                        'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                        'data_conferma' => now(),
                    ]);
                } else {
                    EventoPartecipante::create([
                        'associazione_id' => $associazioneId,
                        'evento_id' => $evento->id,
                        'socio_id' => $socio->id,
                        'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                        'data_conferma' => now(),
                        'note' => null,
                    ]);
                }

                return ['already_checked_in' => false];
            });
        } catch (\Throwable $e) {
            Log::error('Errore check-in evento', [
                'evento_id' => $evento->id,
                'socio_id' => $socio->id,
                'exception' => $e->getMessage(),
            ]);

            return $this->error(500, 'db_error', 'Errore interno durante il check-in.');
        }

        $socioPayload = [
            'nome' => $socio->nome,
            'cognome' => $socio->cognome,
            'numero_socio' => $socio->numero_socio,
        ];

        if ($result['already_checked_in']) {
            return response()->json([
                'success' => true,
                'already_checked_in' => true,
                'message' => 'Socio già registrato a questo evento.',
                'socio' => $socioPayload,
            ]);
        }

        return response()->json([
            'success' => true,
            'already_checked_in' => false,
            'message' => 'Check-in effettuato con successo.',
            'socio' => $socioPayload,
            'evento' => $evento->titolo,
        ]);
    }

    /**
     * Lookup evento: chiave scoped → vincolata al tenant della chiave;
     * chiave globale → solo per id.
     */
    private function findEvento(ApiKey $apiKey, string $id): ?Evento
    {
        return Evento::allTenants()
            ->where('id', $id)
            ->when(! $apiKey->isGlobale(), fn ($q) => $q->where('associazione_id', $apiKey->associazione_id))
            ->first();
    }

    /**
     * Risoluzione del socio dal body, in ordine di priorità (parità legacy):
     * socio_id → tessera_id → numero_tessera (vince l'anno_validita più alto)
     * → numero_socio. Tutte le lookup sono vincolate al tenant dell'evento.
     */
    private function risolviSocioId(Request $request, string $associazioneId): ?string
    {
        $socioId = $request->input('socio_id');
        if (is_string($socioId) && $socioId !== '') {
            return $socioId;
        }

        $tesseraId = $request->input('tessera_id');
        if (is_string($tesseraId) && $tesseraId !== '') {
            $found = Tessera::allTenants()
                ->where('id', $tesseraId)
                ->where('associazione_id', $associazioneId)
                ->value('socio_id');

            if ($found !== null) {
                return $found;
            }
        }

        $numeroTessera = $request->input('numero_tessera');
        if ((is_string($numeroTessera) || is_numeric($numeroTessera)) && (string) $numeroTessera !== '') {
            $found = Tessera::allTenants()
                ->where('numero_tessera', (string) $numeroTessera)
                ->where('associazione_id', $associazioneId)
                ->orderByDesc('anno_validita')
                ->value('socio_id');

            if ($found !== null) {
                return $found;
            }
        }

        $numeroSocio = $request->input('numero_socio');
        if ((is_string($numeroSocio) || is_numeric($numeroSocio)) && (string) $numeroSocio !== '') {
            $found = Socio::allTenants()
                ->where('numero_socio', (string) $numeroSocio)
                ->where('associazione_id', $associazioneId)
                ->value('id');

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function isValidUuid(string $id): bool
    {
        return (bool) preg_match('/^[a-f0-9-]{36}$/i', $id);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ], $status);
    }
}
