<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\ManualePagina;
use Illuminate\View\View;

/**
 * Guide per i soci (issue #12): le pagine del manuale operativo con
 * visibilità "soci", in sola lettura. Le altre restano interne allo staff.
 */
class ManualeController extends Controller
{
    public function index(): View
    {
        $pagine = ManualePagina::query()
            ->where('visibilita', 'soci')
            ->orderBy('sezione')
            ->orderBy('ordine')
            ->orderBy('titolo')
            ->get(['id', 'sezione', 'titolo']);

        return view('area-soci.manuale.index', ['perSezione' => $pagine->groupBy('sezione')]);
    }

    public function show(ManualePagina $pagina): View
    {
        abort_unless($pagina->visibilita === 'soci', 404);

        return view('area-soci.manuale.show', ['pagina' => $pagina]);
    }
}
