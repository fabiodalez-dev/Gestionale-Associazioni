<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\StoreQuotaRequest;
use App\Http\Requests\Quote\UpdateQuotaRequest;
use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Models\Socio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QuotaController extends Controller
{
    use AuthorizesRequests;

    /**
     * Lista quote con filtro anno (SQL) e filtro stato.
     * Lo stato è calcolato dalle date (la colonna `stato` è inaffidabile, §3 spec):
     * qui il filtro è espresso in SQL sulle stesse regole di Quota::statoCalcolato().
     */
    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quota::class);

        $year = $request->query('year', 'Tutti');
        $status = $request->query('status', 'Tutti');

        $oggi = now()->startOfDay();
        $sogliaInScadenza = $oggi->copy()->addDays(30);

        $query = Quota::query()
            ->with(['socio' => fn ($q) => $q->withTrashed()])
            ->orderByDesc('data_scadenza');

        if ($year !== 'Tutti' && $year !== '') {
            $query->where('anno', (int) $year);
        }

        match ($status) {
            Quota::STATO_PAGATA => $query->whereNotNull('data_pagamento'),
            Quota::STATO_SCADUTA => $query->whereNull('data_pagamento')
                ->whereDate('data_scadenza', '<', $oggi),
            Quota::STATO_IN_SCADENZA => $query->whereNull('data_pagamento')
                ->whereDate('data_scadenza', '>=', $oggi)
                ->whereDate('data_scadenza', '<=', $sogliaInScadenza),
            Quota::STATO_DA_PAGARE => $query->whereNull('data_pagamento')
                ->whereDate('data_scadenza', '>', $sogliaInScadenza),
            default => null,
        };

        $quote = $query->paginate(25)->withQueryString();

        $anni = Quota::query()->distinct()->orderByDesc('anno')->pluck('anno');

        $config = PaymentGatewaySetting::forTenant(session('associazione_id'));
        // Check forte uniformato (§4.6 spec): bottoni mostrati solo se il gateway è davvero operativo
        $hasStripe = $config !== null && $config->isStripeOperational();
        $hasPayPal = $config !== null && $config->isPayPalOperational();

        return view('quote.index', [
            'quote' => $quote,
            'anni' => $anni,
            'filtroAnno' => $year,
            'filtroStato' => $status,
            'hasStripe' => $hasStripe,
            'hasPayPal' => $hasPayPal,
        ]);
    }

    public function store(StoreQuotaRequest $request): RedirectResponse
    {
        $this->authorize('create', Quota::class);

        Quota::create([
            ...$request->validated(),
            'stato' => Quota::STATO_DA_PAGARE,
        ]);

        return redirect()->route('quote.index')->with('success', 'Quota creata.');
    }

    public function update(UpdateQuotaRequest $request, Quota $quota): RedirectResponse
    {
        $this->authorize('update', $quota);

        $quota->update($request->validated());

        return redirect()->route('quote.index')->with('success', 'Quota aggiornata.');
    }

    public function destroy(Quota $quota): RedirectResponse
    {
        $this->authorize('delete', $quota);

        // Blocco eliminazione per quote pagate online (tracciabilità contabile, §10.5 spec)
        if (filled($quota->gateway_transaction_id)) {
            return redirect()->route('quote.index')
                ->with('error', 'Impossibile eliminare una quota pagata online (tracciabilità contabile).');
        }

        $quota->delete();

        return redirect()->route('quote.index')->with('success', 'Quota eliminata con successo.');
    }

    /**
     * Autocomplete soci per il form quota (JSON, scoped sul tenant dal global scope).
     */
    public function sociSearch(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Quota::class);

        $q = trim((string) $request->query('q', ''));

        if ($q === '') {
            return response()->json([]);
        }

        $soci = Socio::query()
            ->where(function ($query) use ($q) {
                $query->where('nome', 'like', "%{$q}%")
                    ->orWhere('cognome', 'like', "%{$q}%");
            })
            ->orderBy('cognome')
            ->orderBy('nome')
            ->limit(20)
            ->get(['id', 'nome', 'cognome'])
            ->map(fn (Socio $socio) => [
                'id' => $socio->id,
                'nome_completo' => trim($socio->cognome.' '.$socio->nome),
            ])
            ->values();

        return response()->json($soci);
    }
}
