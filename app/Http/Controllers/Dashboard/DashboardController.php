<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Dashboard con statistiche.
     *
     * Vista tenant (utente con associazione o super_admin con tenant selezionato)
     * oppure vista globale (super_admin senza tenant): lo scoping è demandato
     * al global scope BelongsToAssociazione, che in vista globale non filtra.
     */
    public function index(Request $request): View
    {
        /** @var Utente $utente */
        $utente = $request->user();

        $oggi = now()->startOfDay();

        $stats = [
            'total_soci' => Socio::query()->count(),
            'soci_attivi' => Socio::query()->where('stato', 'Attivo')->count(),
            'eventi_mese' => Evento::query()
                ->whereBetween('data_evento', [now()->startOfMonth(), now()->endOfMonth()])
                ->count(),
            'quote_pagate' => Quota::query()
                ->where('stato', 'Pagata')
                ->where('anno', now()->year)
                ->count(),
            'scadenze_imminenti' => Tessera::query()
                ->where('stato', 'Attiva')
                ->whereBetween('data_scadenza', [$oggi, $oggi->copy()->addDays(30)->endOfDay()])
                ->count(),
        ];

        $prossimiEventi = Evento::query()
            ->where('data_evento', '>=', $oggi)
            ->orderBy('data_evento')
            ->limit(5)
            ->get(['id', 'titolo', 'data_evento']);

        // Card di context switch per il super_admin (associazioni attive)
        $associazioni = $utente->isSuperAdmin()
            ? Associazione::query()->where('attiva', true)->orderBy('nome')->get(['id', 'nome'])
            : collect();

        return view('dashboard.index', [
            'utente' => $utente,
            'stats' => $stats,
            'prossimiEventi' => $prossimiEventi,
            'associazioni' => $associazioni,
            'vistaGlobale' => $utente->isSuperAdmin() && blank(session('associazione_id')),
        ]);
    }
}
