<?php

namespace App\Http\Controllers\Tessere;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tessere\CheckinTesseraRequest;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

/**
 * Check-in dei soci agli eventi tramite scansione della tessera
 * (porta del flusso legacy checkin.php + POST /api/v1/eventi/{id}/checkin,
 * qui in area admin con sessione/tenant al posto della chiave API).
 */
class CheckinController extends Controller
{
    /**
     * Pagina check-in: selezione evento + scanner QR + input manuale.
     */
    public function index(): View
    {
        $eventi = Evento::query()
            ->orderByDesc('data_evento')
            ->limit(50)
            ->get(['id', 'titolo', 'data_evento', 'luogo']);

        return view('tessere.checkin', [
            'eventi' => $eventi,
        ]);
    }

    /**
     * Registra il check-in (stato_partecipazione 'Confermato').
     * Identificazione socio: tessera_id (dal QR) → numero_tessera → numero_socio.
     */
    public function store(CheckinTesseraRequest $request): JsonResponse
    {
        $dati = $request->validated();
        $associazioneId = (string) session('associazione_id');

        /** @var Evento $evento */
        $evento = Evento::query()->findOrFail($dati['evento_id']);

        $socioId = null;

        if (! empty($dati['tessera_id'])) {
            $socioId = Tessera::query()
                ->whereKey($dati['tessera_id'])
                ->value('socio_id');
        }

        if ($socioId === null && ! empty($dati['numero_tessera'])) {
            $socioId = Tessera::query()
                ->where('numero_tessera', $dati['numero_tessera'])
                ->orderByDesc('anno_validita')
                ->value('socio_id');
        }

        if ($socioId === null && ! empty($dati['numero_socio'])) {
            $socioId = Socio::query()
                ->where('numero_socio', $dati['numero_socio'])
                ->value('id');
        }

        if ($socioId === null) {
            return response()->json([
                'success' => false,
                'error' => 'Socio non identificato. Fornisci tessera, numero tessera o numero socio.',
                'code' => 'socio_not_found',
            ], 404);
        }

        /** @var Socio|null $socio */
        $socio = Socio::query()->find($socioId);

        if ($socio === null) {
            return response()->json([
                'success' => false,
                'error' => 'Socio non trovato nell\'associazione.',
                'code' => 'socio_not_found',
            ], 404);
        }

        if (! $socio->isAttivo()) {
            return response()->json([
                'success' => false,
                'error' => 'Il socio non è attivo (stato: '.$socio->stato.').',
                'code' => 'socio_not_active',
            ], 400);
        }

        $partecipante = EventoPartecipante::query()
            ->where('evento_id', $evento->id)
            ->where('socio_id', $socio->id)
            ->first();

        if ($partecipante !== null && $partecipante->stato_partecipazione === EventoPartecipante::STATO_CONFERMATO) {
            return response()->json([
                'success' => true,
                'already_checked_in' => true,
                'message' => 'Socio già registrato per questo evento.',
                'socio' => [
                    'nome' => $socio->nome,
                    'cognome' => $socio->cognome,
                    'numero_socio' => $socio->numero_socio,
                ],
            ]);
        }

        if ($partecipante !== null) {
            $partecipante->update([
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
            ]);
        }

        return response()->json([
            'success' => true,
            'already_checked_in' => false,
            'message' => 'Check-in effettuato con successo.',
            'socio' => [
                'nome' => $socio->nome,
                'cognome' => $socio->cognome,
                'numero_socio' => $socio->numero_socio,
            ],
            'evento' => $evento->titolo,
        ]);
    }
}
