<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\AreaSoci\TesseraQrService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Dashboard del socio: prossima quota da pagare, tessera attiva con QR,
     * prossimi eventi a cui è iscritto (miglioramento rispetto al placeholder legacy).
     */
    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        // Come il legacy: solo quote non pagate CON scadenza futura (le già scadute non appaiono qui)
        $prossimaQuota = $socio->quote()
            ->whereNull('data_pagamento')
            ->whereDate('data_scadenza', '>=', today())
            ->orderBy('data_scadenza')
            ->first();

        $tessera = $socio->tessere()
            ->where('stato', Tessera::STATO_ATTIVA)
            ->orderByDesc('anno_validita')
            ->first();

        $prossimiEventi = $socio->eventi()
            ->where('data_evento', '>=', now())
            ->orderBy('data_evento')
            ->limit(5)
            ->get();

        return view('area-soci.dashboard', [
            'socio' => $socio,
            'prossimaQuota' => $prossimaQuota,
            'tessera' => $tessera,
            'tesseraQr' => $tessera !== null ? TesseraQrService::svgDataUri($tessera) : null,
            'prossimiEventi' => $prossimiEventi,
        ]);
    }
}
