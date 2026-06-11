<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\PaymentGatewaySetting;
use App\Models\Prenotazione;
use App\Services\Pagamenti\PaymentService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pagamento di una prenotazione dal frontend pubblico (guard 'account',
 * verificato): scelta del gateway con countdown sulla scadenza (+30'),
 * redirect al checkout Stripe/PayPal, ritorno sull'esito con stato live —
 * la conferma reale arriva SEMPRE dal webhook, mai dal redirect.
 *
 * Nota: {prenotazione} è un parametro stringa, NON binding implicito
 * (scatterebbe prima che RisolviTenantPubblico bindi tenant.pubblico):
 * risolto qui sotto tenant scope → 404 uniforme cross-tenant.
 */
class PagamentoPrenotazioneController extends Controller
{
    public function __construct(protected PaymentService $paymentService) {}

    /**
     * GET /a/{slug}/prenotazioni/{prenotazione}/paga
     *
     * Scelta del gateway + countdown sulla scadenza del pagamento.
     */
    public function paga(Request $request, Associazione $associazione, string $prenotazione): View|RedirectResponse
    {
        $prenotazione = $this->resolvePrenotazione($request, $prenotazione);

        // Non in attesa di pagamento (già confermata dal webhook, scaduta,
        // annullata, in lista d'attesa): l'esito mostra lo stato corretto
        if ($prenotazione->stato !== Prenotazione::STATO_IN_ATTESA_PAGAMENTO) {
            return redirect()->route('pubblico.prenotazioni.esito', [
                'associazione' => $associazione->slug,
                'prenotazione' => $prenotazione->id,
            ]);
        }

        $config = PaymentGatewaySetting::forTenant($prenotazione->associazione_id);

        return view('pubblico.prenotazioni.paga', [
            'associazione' => $associazione,
            'prenotazione' => $prenotazione,
            'evento' => $prenotazione->evento,
            'hasStripe' => $config !== null && $config->isStripeOperational(),
            'hasPayPal' => $config !== null && $config->isPayPalOperational(),
            'valuta' => strtoupper($config->valuta ?? 'EUR'),
            'pagabile' => $this->isPagabileOra($prenotazione),
        ]);
    }

    /**
     * POST /a/{slug}/prenotazioni/{prenotazione}/checkout/{gateway}
     *
     * Avvia il pagamento sul gateway scelto (stripe|paypal, vincolato
     * dalla rotta) e redirige all'URL di checkout del gateway.
     */
    public function checkout(Request $request, Associazione $associazione, string $prenotazione, string $gateway): RedirectResponse
    {
        $prenotazione = $this->resolvePrenotazione($request, $prenotazione);

        // Guard server-side: si paga solo una prenotazione in attesa, entro la finestra
        if (! $this->isPagabileOra($prenotazione)) {
            return redirect()->route('pubblico.prenotazioni.paga', [
                'associazione' => $associazione->slug,
                'prenotazione' => $prenotazione->id,
            ]);
        }

        $returnUrl = route('pubblico.prenotazioni.esito', [
            'associazione' => $associazione->slug,
            'prenotazione' => $prenotazione->id,
            'esito' => 'success',
        ]);
        $cancelUrl = route('pubblico.prenotazioni.esito', [
            'associazione' => $associazione->slug,
            'prenotazione' => $prenotazione->id,
            'esito' => 'cancel',
        ]);

        $url = $gateway === 'stripe'
            ? $this->paymentService->createStripeCheckoutPrenotazione($prenotazione, $returnUrl, $cancelUrl)
            : $this->paymentService->createPayPalOrderPrenotazione($prenotazione, $returnUrl, $cancelUrl);

        if ($url !== null) {
            return redirect()->away($url);
        }

        return redirect()->route('pubblico.prenotazioni.paga', [
            'associazione' => $associazione->slug,
            'prenotazione' => $prenotazione->id,
        ])->with('checkout_error', 'Errore nella creazione del pagamento. Riprova.');
    }

    /**
     * Lookup tenant-scoped (404 uniforme cross-tenant) + policy:
     * SOLO il proprietario della prenotazione.
     */
    protected function resolvePrenotazione(Request $request, string $prenotazioneId): Prenotazione
    {
        /** @var Prenotazione $prenotazione */
        $prenotazione = Prenotazione::query()
            ->with(['evento' => fn ($q) => $q->withoutGlobalScope('associazione')])
            ->findOrFail($prenotazioneId);

        Gate::forUser($request->user('account'))->authorize('paga', $prenotazione);

        return $prenotazione;
    }

    /**
     * La prenotazione è pagabile ora? In attesa di pagamento e con la
     * finestra (scadenza_pagamento_at) non ancora superata.
     */
    protected function isPagabileOra(Prenotazione $prenotazione): bool
    {
        return $prenotazione->stato === Prenotazione::STATO_IN_ATTESA_PAGAMENTO
            && ($prenotazione->scadenza_pagamento_at === null || $prenotazione->scadenza_pagamento_at->isFuture());
    }
}
