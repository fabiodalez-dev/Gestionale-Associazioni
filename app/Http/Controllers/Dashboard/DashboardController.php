<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Carica;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\Utente;
use Illuminate\Database\Eloquent\Collection;
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
            'kpiPrenotazioni' => $this->kpiPrenotazioni(),
            'caricheInScadenza' => $this->caricheInScadenza(),
        ]);
    }

    /**
     * Mandati degli organi sociali in scadenza nei prossimi 90 giorni
     * (f.16 del piano vita sociale). Solo in vista tenant: in vista
     * globale super_admin la sezione non viene mostrata (null).
     *
     * @return Collection<int, Carica>|null
     */
    private function caricheInScadenza(): ?Collection
    {
        if (Carica::currentAssociazioneId() === null) {
            return null;
        }

        return Carica::query()
            ->inScadenza()
            ->with(['socio' => fn ($q) => $q->withTrashed()])
            ->orderBy('fine')
            ->get();
    }

    /**
     * KPI del sistema di prenotazione pubblico (docs/PIANO-PRENOTAZIONI.md,
     * sezione Admin). Calcolati SOLO in vista tenant e SOLO se il tenant
     * ha il frontend pubblico attivo; altrimenti null (sezione nascosta).
     *
     * @return array<string, mixed>|null
     */
    private function kpiPrenotazioni(): ?array
    {
        $tenantId = Evento::currentAssociazioneId();

        if ($tenantId === null) {
            return null; // vista globale super_admin: nessun KPI prenotazioni
        }

        $associazione = Associazione::query()->find($tenantId);

        if ($associazione === null || ! $associazione->frontend_pubblico_attivo) {
            return null;
        }

        // Riempimento dei prossimi 5 eventi pubblicati (posti confermati / capienza)
        $riempimento = Evento::query()
            ->pubblicati()
            ->where('data_evento', '>=', now())
            ->orderBy('data_evento')
            ->limit(5)
            ->get(['id', 'titolo', 'data_evento', 'capienza'])
            ->map(function (Evento $evento): array {
                $confermati = (int) Prenotazione::query()
                    ->where('evento_id', $evento->id)
                    ->confermate()
                    ->sum('posti');

                return [
                    'evento' => $evento,
                    'confermati' => $confermati,
                    'capienza' => $evento->capienza,
                    'percentuale' => $evento->capienza !== null && $evento->capienza > 0
                        ? (int) round(min(100, $confermati * 100 / $evento->capienza))
                        : null,
                ];
            });

        return [
            'confermate_30gg' => Prenotazione::query()
                ->confermate()
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
            'incasso_mese' => (float) Prenotazione::query()
                ->whereNotNull('pagata_at')
                ->whereBetween('pagata_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('importo_totale'),
            'in_lista_attesa' => Prenotazione::query()
                ->where('stato', Prenotazione::STATO_LISTA_ATTESA)
                ->count(),
            'account_registrati' => Account::query()->count(),
            'riempimento' => $riempimento,
        ];
    }
}
