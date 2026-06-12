<?php

namespace App\Http\Controllers\Economia;

use App\Http\Controllers\Controller;
use App\Models\Donazione;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Elenco donazioni admin (f.15 del piano "Gestione 2"): sola lettura,
 * filtro per stato, totale incassato del tenant.
 */
class DonazioneAdminController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Donazione::class);

        $query = Donazione::query()->orderByDesc('created_at');

        $stato = $request->query('stato');

        if (in_array($stato, Donazione::STATI, true)) {
            $query->where('stato', $stato);
        }

        return view('economia.donazioni.index', [
            'donazioni' => $query->paginate(25)->withQueryString(),
            'totalePagate' => (float) Donazione::query()->pagate()->sum('importo'),
            'numeroPagate' => Donazione::query()->pagate()->count(),
            'filtroStato' => $stato,
        ]);
    }
}
