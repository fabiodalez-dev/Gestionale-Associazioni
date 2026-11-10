<?php

namespace App\Http\Controllers\Tessere;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tessere\CheckinTesseraRequest;
use App\Models\Evento;
use App\Models\EventoPartecipante;
use App\Models\Prenotazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\EventiVita\PresenzeSociService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
     * Ricerca del socio per chi ha dimenticato la tessera (#50): nome,
     * cognome, email, telefono, numero socio o tessera, almeno 3 caratteri,
     * sulla query scoped dell'operatore. Solo i dati per riconoscerlo.
     */
    public function cercaSoci(Request $request): JsonResponse
    {
        abort_unless($this->puoFareCheckin($request), 403);

        $q = trim((string) $request->query('q', ''));
        if (mb_strlen($q) < 3) {
            return response()->json(['soci' => []]);
        }

        $cifre = (string) preg_replace('/\D/', '', $q);
        $perTessera = Tessera::query()->where('numero_tessera', $q)->pluck('socio_id');
        $parole = preg_split('/\s+/', $q) ?: [];

        $soci = Socio::query()
            ->where(function ($w) use ($parole, $cifre, $perTessera, $q) {
                // Ogni parola nel nome o nel cognome: "Rossi Mario" e "Mario Rossi"
                $w->where(function ($nome) use ($parole) {
                    foreach ($parole as $parola) {
                        $nome->where(fn ($p) => $p->where('nome', 'like', '%'.$parola.'%')->orWhere('cognome', 'like', '%'.$parola.'%'));
                    }
                })
                    ->orWhere('email', 'like', '%'.$q.'%')
                    ->orWhere('numero_socio', $q)
                    ->orWhereIn('id', $perTessera);
                if (strlen($cifre) >= 6) {
                    $w->orWhereRaw("REPLACE(REPLACE(REPLACE(COALESCE(telefono, ''), ' ', ''), '-', ''), '+39', '') LIKE ?", ['%'.$cifre]);
                }
            })
            ->orderBy('cognome')
            ->orderBy('nome')
            ->limit(8)
            ->get(['id', 'nome', 'cognome', 'numero_socio', 'stato']);

        return response()->json(['soci' => $soci->map(fn (Socio $socio) => [
            'id' => $socio->id,
            'nome' => trim($socio->cognome.' '.$socio->nome),
            'numero_socio' => $socio->numero_socio,
            'stato' => $socio->stato,
        ])->values()]);
    }

    private function puoFareCheckin(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && ((method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
                || $user->can('eventi.update')
                || $user->can('tessere.view'));
    }

    /**
     * Registra il check-in (stato_partecipazione 'Confermato') e segna
     * l'eventuale prenotazione confermata del socio per lo stesso evento.
     * Identificazione socio: tessera_id (dal QR) → numero_tessera → numero_socio.
     */
    public function store(CheckinTesseraRequest $request, PresenzeSociService $presenze): JsonResponse
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

        // Scelto per nome: stessa query scoped (tenant e perimetro di sede)
        if ($socioId === null && ! empty($dati['socio_id'])) {
            $socioId = Socio::query()->whereKey($dati['socio_id'])->value('id');
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

        // Socio con tessere ma nessuna valida (scadute, annullate, sospese):
        // il tesseramento non è stato rinnovato, il check-in va rifiutato con
        // lo stesso criterio della verifica pubblica (Tessera::isValida).
        // Un socio senza alcuna tessera emessa resta identificabile per numero socio.
        // Tutte le tessere del socio, anche fuori dal perimetro di sede
        // dell'operatore: una tessera valida emessa altrove vale comunque
        $tessereSocio = Tessera::withoutGlobalScopes()
            ->where('associazione_id', $socio->associazione_id)
            ->where('socio_id', $socio->id);

        if ((clone $tessereSocio)->exists() && ! (clone $tessereSocio)->valide()->exists()) {
            return response()->json([
                'success' => false,
                'error' => 'Tessera scaduta o non rinnovata: il socio non ha una tessera valida.',
                'code' => 'tessera_non_valida',
            ], 400);
        }

        // Prenotazione e partecipazione nella stessa transazione: un errore
        // sulla seconda scrittura annulla anche la prima
        $giaRegistrato = DB::transaction(function () use ($evento, $socio, $presenze, $request, $associazioneId): bool {
            $partecipante = EventoPartecipante::query()
                ->where('evento_id', $evento->id)
                ->where('socio_id', $socio->id)
                ->first();

            // La prenotazione del socio (account collegato) per l'evento risulta
            // arrivata per il suo posto (origine "tessera"): vita sociale,
            // feedback e statistiche contano la stessa presenza; il check-in si
            // annulla se il partecipante viene poi rimosso o non è più Confermato
            $presenze->segnaPrenotazioneDelSocio(
                $evento,
                $socio,
                $request->user()?->getAuthIdentifier(),
                Prenotazione::CHECKIN_ORIGINE_TESSERA,
            );

            if ($partecipante !== null && $partecipante->stato_partecipazione === EventoPartecipante::STATO_CONFERMATO) {
                return true;
            }

            if ($partecipante !== null) {
                $partecipante->update([
                    'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                    'data_conferma' => now(),
                ]);
            } else {
                try {
                    EventoPartecipante::create([
                        'associazione_id' => $associazioneId,
                        'evento_id' => $evento->id,
                        'socio_id' => $socio->id,
                        'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                        'data_conferma' => now(),
                    ]);
                } catch (UniqueConstraintViolationException $e) {
                    // Riga creata in parallelo (altra scansione o operazione
                    // admin, unique evento+socio): riletta con lock, perché la
                    // lettura iniziale della transazione può non vederla
                    $partecipante = EventoPartecipante::query()
                        ->where('evento_id', $evento->id)
                        ->where('socio_id', $socio->id)
                        ->lockForUpdate()
                        ->first();

                    if ($partecipante === null) {
                        throw $e;
                    }

                    if ($partecipante->stato_partecipazione === EventoPartecipante::STATO_CONFERMATO) {
                        return true;
                    }

                    $partecipante->update([
                        'stato_partecipazione' => EventoPartecipante::STATO_CONFERMATO,
                        'data_conferma' => now(),
                    ]);
                }
            }

            return false;
        });

        if ($giaRegistrato) {
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
