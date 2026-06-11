<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\Socio;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuoteController extends Controller
{
    /**
     * Storico quote del socio. Lo stato è SEMPRE calcolato a runtime
     * (Quota::statoCalcolato) — la colonna `stato` del DB è inaffidabile.
     */
    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $quote = $socio->quote()
            ->orderByDesc('anno')
            ->orderByDesc('data_scadenza')
            ->get();

        // Finestra "In Scadenza": configurazione dell'associazione (default 30gg come il legacy)
        $giorniPreavviso = (int) ($socio->associazione?->giorni_notifica_scadenza ?? 30);

        return view('area-soci.quote', [
            'socio' => $socio,
            'quote' => $quote,
            'giorniPreavviso' => $giorniPreavviso,
        ]);
    }
}
