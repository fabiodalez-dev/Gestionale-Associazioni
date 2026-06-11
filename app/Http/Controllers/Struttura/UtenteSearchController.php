<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Utente;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * API ricerca utenti per l'autocomplete "collega admin esistente"
 * (sostituisce api/users_search.php — spec §1.7, irrobustita: i filtri
 * "disponibile" ed "escludi super_admin" sono applicati server-side, §9.15).
 */
class UtenteSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', Utente::class);

        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 3) {
            return response()->json(['success' => true, 'items' => []]);
        }

        $query = Utente::query()
            ->where('ruolo', '<>', Utente::RUOLO_SUPER_ADMIN)
            ->where(function ($builder) use ($q) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%';
                $builder->where('nome', 'like', $like)
                    ->orWhere('cognome', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });

        // Solo utenti non ancora collegati a un'associazione
        if ($request->boolean('available')) {
            $query->whereNull('associazione_id');
        }

        $items = $query->orderBy('nome')
            ->limit(20)
            ->get(['id', 'nome', 'cognome', 'email', 'ruolo', 'associazione_id'])
            ->map(fn (Utente $utente) => [
                'id' => $utente->id,
                'nome' => $utente->nome_completo,
                'email' => $utente->email,
                'ruolo' => $utente->ruolo,
                'associazione_id' => $utente->associazione_id,
            ]);

        return response()->json(['success' => true, 'items' => $items]);
    }
}
