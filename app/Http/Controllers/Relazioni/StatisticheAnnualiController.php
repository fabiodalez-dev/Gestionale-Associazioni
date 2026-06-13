<?php

namespace App\Http\Controllers\Relazioni;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Utente;
use App\Services\Relazioni\StatisticheAnnualiService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * "Anno in cifre" (f.25 del piano "Organizzazione 3"): pagina Statistiche
 * annuali con confronto anno corrente vs precedente (nuovi soci, cessazioni,
 * retention, tessere, partecipazioni, ore volontariato, entrate/uscite,
 * donazioni), grafici Chart.js ed export PDF per l'assemblea.
 *
 * Autorizzazione: struttura.view (visione organizzativa trasversale),
 * fallback admin_associazione — come il Registro modifiche.
 */
class StatisticheAnnualiController extends Controller
{
    public function __construct(private readonly StatisticheAnnualiService $statistiche) {}

    public function index(Request $request): View
    {
        $this->autorizza($request);

        $anno = $this->annoRichiesto($request);
        $dati = $this->statistiche->confronto($anno);

        return view('relazioni.statistiche.index', [
            'dati' => $dati,
            'anno' => $anno,
            'anniDisponibili' => $this->statistiche->anniDisponibili(),
        ]);
    }

    public function pdf(Request $request): Response
    {
        $this->autorizza($request);

        $anno = $this->annoRichiesto($request);
        $dati = $this->statistiche->confronto($anno);

        $associazione = $this->associazioneCorrente($request);

        $pdf = Pdf::loadView('relazioni.statistiche.pdf', [
            'dati' => $dati,
            'anno' => $anno,
            'associazione' => $associazione,
        ])->setPaper('a4', 'portrait');

        return $pdf->download('statistiche-annuali-'.$anno.'-'.now()->format('Ymd').'.pdf');
    }

    private function annoRichiesto(Request $request): int
    {
        $anno = (int) $request->query('anno', (string) date('Y'));

        // Limiti di sanità: nessuna proiezione assurda
        if ($anno < 2000 || $anno > ((int) date('Y') + 1)) {
            $anno = (int) date('Y');
        }

        return $anno;
    }

    private function autorizza(Request $request): void
    {
        /** @var Utente $utente */
        $utente = $request->user();

        abort_unless($this->autorizzato($utente), 403);
    }

    private function autorizzato(Utente $utente): bool
    {
        if ($utente->isSuperAdmin()) {
            return true;
        }

        try {
            return $utente->hasPermissionTo('struttura.view');
        } catch (\Throwable) {
            return $utente->isAdminAssociazione();
        }
    }

    private function associazioneCorrente(Request $request): ?Associazione
    {
        $tenantId = session('associazione_id') ?? $request->user()?->associazione_id;

        return $tenantId !== null ? Associazione::query()->find($tenantId) : null;
    }
}
