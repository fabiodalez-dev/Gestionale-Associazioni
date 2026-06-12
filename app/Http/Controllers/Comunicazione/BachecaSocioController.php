<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Models\Annuncio;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bacheca dell'area soci (f.7 piano Gestione 2): tutti gli annunci
 * pubblicati del tenant, INCLUSI quelli riservati ai soci (solo_soci).
 * Il tenant è quello del socio autenticato (global scope, guard 'socio').
 */
class BachecaSocioController extends Controller
{
    public function index(Request $request): View
    {
        $annunci = Annuncio::query()
            ->pubblicati()
            ->orderByDesc('in_evidenza')
            ->orderByDesc('pubblicato_dal')
            ->paginate(10);

        return view('comunicazione.area-soci.bacheca', [
            'socio' => $request->user('socio'),
            'annunci' => $annunci,
        ]);
    }
}
