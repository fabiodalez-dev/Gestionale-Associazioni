<?php

namespace App\Http\Controllers\Economia;

use App\Http\Controllers\Controller;
use App\Models\Quota;
use App\Services\Economia\SollecitoQuoteService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Solleciti quote non pagate (f.16 del piano "Gestione 2"): lista delle
 * quote da pagare con conteggio solleciti e data ultimo invio, invio
 * singolo e in blocco dell'email di sollecito col link di pagamento.
 * Autorizzazione: QuotaPolicy (quote.view per la lista, quote.update
 * per l'invio).
 */
class SollecitoQuoteController extends Controller
{
    use AuthorizesRequests;

    public function __construct(protected SollecitoQuoteService $solleciti) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Quota::class);

        $query = Quota::query()
            ->with('socio')
            ->whereNull('data_pagamento')
            ->orderBy('data_scadenza')
            ->orderBy('created_at');

        if (filled($request->query('anno')) && $request->query('anno') !== 'Tutti') {
            $query->where('anno', (int) $request->query('anno'));
        }

        $quote = $query->paginate(25)->withQueryString();

        return view('economia.solleciti.index', [
            'quote' => $quote,
            'solleciti' => $this->solleciti->statoSolleciti($quote->items()),
            'anni' => Quota::query()->whereNull('data_pagamento')
                ->select('anno')->distinct()->orderByDesc('anno')->pluck('anno'),
            'filtroAnno' => $request->query('anno', 'Tutti'),
        ]);
    }

    /**
     * Sollecito singolo.
     */
    public function invia(Quota $quota): RedirectResponse
    {
        $this->authorize('update', $quota);

        $inviato = $this->solleciti->invia($quota);

        return redirect()->route('solleciti-quote.index')
            ->with(
                $inviato ? 'success' : 'error',
                $inviato
                    ? 'Sollecito inviato.'
                    : 'Sollecito non inviato: quota già pagata oppure socio senza email (o con opt-out).'
            );
    }

    /**
     * Sollecito in blocco sulle quote selezionate.
     */
    public function inviaBulk(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'quota_ids' => ['required', 'array', 'min:1'],
            'quota_ids.*' => ['uuid'],
        ], [
            'quota_ids.required' => 'Seleziona almeno una quota da sollecitare.',
        ]);

        // Lookup tenant-scoped: gli id di altri tenant semplicemente non esistono
        $quote = Quota::query()
            ->with('socio')
            ->whereIn('id', $validated['quota_ids'])
            ->whereNull('data_pagamento')
            ->get();

        foreach ($quote as $quota) {
            $this->authorize('update', $quota);
        }

        if ($quote->isEmpty()) {
            return redirect()->route('solleciti-quote.index')
                ->with('error', 'Nessuna quota sollecitabile tra quelle selezionate.');
        }

        [$inviati, $saltati] = $this->solleciti->inviaBulk($quote);

        return redirect()->route('solleciti-quote.index')
            ->with('success', "Solleciti inviati: {$inviati}".($saltati > 0 ? " — saltati: {$saltati} (senza email o con opt-out)." : '.'));
    }
}
