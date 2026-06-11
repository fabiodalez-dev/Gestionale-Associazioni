<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\View\View;

/**
 * Home del mini-sito pubblico (/a/{slug}): hero brandizzato del tenant
 * + anteprima dei prossimi eventi pubblicati.
 */
class HomeController extends Controller
{
    public function show(Associazione $associazione): View
    {
        $prossimiEventi = Evento::query()
            ->where('associazione_id', $associazione->id)
            ->pubblicati()
            ->where('data_evento', '>=', now())
            ->orderBy('data_evento')
            ->limit(6)
            ->get();

        return view('pubblico.home', [
            'associazione' => $associazione,
            'prossimiEventi' => $prossimiEventi,
        ]);
    }
}
