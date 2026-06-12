<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Models\Annuncio;
use App\Models\Associazione;
use Illuminate\View\View;

/**
 * Sezione "Novità" del mini-sito pubblico (f.7 piano Gestione 2):
 * elenco paginato degli annunci pubblicati NON riservati ai soci.
 */
class NovitaPubblicheController extends Controller
{
    public function index(Associazione $associazione): View
    {
        $annunci = Annuncio::query()
            ->where('associazione_id', $associazione->id)
            ->pubblici()
            ->orderByDesc('in_evidenza')
            ->orderByDesc('pubblicato_dal')
            ->paginate(10);

        return view('comunicazione.pubblico.novita', [
            'associazione' => $associazione,
            'annunci' => $annunci,
        ]);
    }
}
