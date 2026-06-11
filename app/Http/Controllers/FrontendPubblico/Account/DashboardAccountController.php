<?php

namespace App\Http\Controllers\FrontendPubblico\Account;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Associazione;
use App\Models\Prenotazione;
use App\Services\Prenotazioni\BigliettoService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Dashboard dell'area riservata account (/a/{slug}/account):
 * prossime prenotazioni con QR rapido (confermate) e stato della
 * richiesta "diventa socio".
 */
class DashboardAccountController extends Controller
{
    public function __construct(protected BigliettoService $biglietti) {}

    public function show(Request $request, Associazione $associazione): View
    {
        /** @var Account $account */
        $account = $request->user('account');

        // Prossime prenotazioni: eventi futuri, stati "vivi"
        // (confermata / in attesa di pagamento / lista d'attesa)
        $prossime = $account->prenotazioni()
            ->whereIn('stato', [
                Prenotazione::STATO_CONFERMATA,
                Prenotazione::STATO_IN_ATTESA_PAGAMENTO,
                Prenotazione::STATO_LISTA_ATTESA,
            ])
            ->whereHas('evento', fn ($q) => $q->where('data_evento', '>=', now()))
            ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->get()
            ->sortBy(fn (Prenotazione $p) => $p->evento?->data_evento)
            ->take(5)
            ->values();

        // QR rapido per le confermate (data URI PNG, pronto per <img>)
        $qrRapidi = $prossime
            ->filter(fn (Prenotazione $p) => $p->stato === Prenotazione::STATO_CONFERMATA)
            ->mapWithKeys(fn (Prenotazione $p) => [$p->id => $this->biglietti->qrDataUri($p)]);

        return view('pubblico.account.dashboard', [
            'associazione' => $associazione,
            'account' => $account,
            'prossime' => $prossime,
            'qrRapidi' => $qrRapidi,
        ]);
    }
}
