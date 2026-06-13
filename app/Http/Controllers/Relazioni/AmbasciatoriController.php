<?php

namespace App\Http\Controllers\Relazioni;

use App\Http\Controllers\Controller;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

/**
 * Classifica "ambasciatori" del programma porta-un-amico (f.23 del piano
 * "Organizzazione 3"): soci ordinati per numero di iscrizioni APPROVATE
 * arrivate con il loro codice referral. Autorizzazione: SocioPolicy
 * (soci.view), coerente con la voce di sidebar.
 */
class AmbasciatoriController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', Socio::class);

        // Conteggio iscrizioni approvate per codice referral del tenant
        // (il global scope su RichiestaIscrizione garantisce l'isolamento).
        $approvatePerCodice = RichiestaIscrizione::query()
            ->where('stato', RichiestaIscrizione::STATO_APPROVATA)
            ->whereNotNull('referral_codice')
            ->selectRaw('referral_codice, COUNT(*) as totale')
            ->groupBy('referral_codice')
            ->pluck('totale', 'referral_codice');

        $totaleInvitiPerCodice = RichiestaIscrizione::query()
            ->whereNotNull('referral_codice')
            ->selectRaw('referral_codice, COUNT(*) as totale')
            ->groupBy('referral_codice')
            ->pluck('totale', 'referral_codice');

        $ambasciatori = Socio::query()
            ->whereIn('codice_referral', $approvatePerCodice->keys()->all())
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get(['id', 'nome', 'cognome', 'numero_socio', 'codice_referral'])
            ->map(function (Socio $socio) use ($approvatePerCodice, $totaleInvitiPerCodice) {
                $socio->setAttribute('iscrizioni_approvate', (int) ($approvatePerCodice[$socio->codice_referral] ?? 0));
                $socio->setAttribute('iscrizioni_totali', (int) ($totaleInvitiPerCodice[$socio->codice_referral] ?? 0));

                return $socio;
            })
            ->sortByDesc('iscrizioni_approvate')
            ->values();

        return view('relazioni.ambasciatori.index', [
            'ambasciatori' => $ambasciatori,
            'totaleApprovate' => (int) $approvatePerCodice->sum(),
        ]);
    }
}
