<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Http\Requests\AreaSoci\AggiornaProfiloRequest;
use App\Models\Socio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfiloController extends Controller
{
    public function show(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        return view('area-soci.profilo', [
            'socio' => $socio,
            'valoriCampi' => $socio->valoriCampiPersonalizzati()->with('campo')->get(),
            'tags' => $socio->tags()->get(),
        ]);
    }

    /**
     * Il socio aggiorna SOLO i propri recapiti (telefono, indirizzo, citta, cap)
     * e la preferenza email_opt_out. Gli altri campi sono read-only server-side.
     */
    public function update(AggiornaProfiloRequest $request): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $dati = $request->safe()->only(['telefono', 'indirizzo', 'citta', 'cap']);
        $dati['email_opt_out'] = $request->boolean('email_opt_out');

        $socio->fill($dati)->save();

        return redirect()
            ->route('area-soci.profilo')
            ->with('success', 'Profilo aggiornato con successo.');
    }
}
