<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Http\Requests\Quote\StoreQuotaRequest;
use App\Http\Requests\Quote\UpdateQuotaRequest;
use App\Models\Associazione;
use App\Models\PaymentGatewaySetting;
use App\Models\Quota;
use App\Models\Socio;
use App\Models\TipoSocio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

        // KPI dell'anno scelto (anno corrente se il filtro è "Tutti")
        $annoKpi = $year !== 'Tutti' && $year !== '' ? (int) $year : (int) $oggi->year;
        $base = fn () => Quota::query()->where('anno', $annoKpi);
        $incassato = (float) $base()->whereNotNull('data_pagamento')->sum('importo');
        $incassatoPrecedente = (float) Quota::query()->where('anno', $annoKpi - 1)->whereNotNull('data_pagamento')->sum('importo');
        $kpi = [
            'anno' => $annoKpi,
            'incassato' => $incassato,
            'variazione' => $incassatoPrecedente > 0 ? round(($incassato - $incassatoPrecedente) * 100 / $incassatoPrecedente, 1) : null,
            'da_incassare' => (float) $base()->whereNull('data_pagamento')->sum('importo'),
            'aperte' => $base()->whereNull('data_pagamento')->count(),
            'scadute' => (float) $base()->whereNull('data_pagamento')->whereDate('data_scadenza', '<', $oggi)->sum('importo'),
            'numero_scadute' => $base()->whereNull('data_pagamento')->whereDate('data_scadenza', '<', $oggi)->count(),
            'pagate' => $base()->whereNotNull('data_pagamento')->count(),
            'online' => $base()->whereNotNull('data_pagamento')->whereIn('metodo_pagamento', ['stripe', 'paypal', 'carta'])->count(),
        ];
        $perAnno = fn () => Quota::query()->when($year !== 'Tutti' && $year !== '', fn ($q) => $q->where('anno', (int) $year));
        $conteggiStato = [
            'Tutti' => $perAnno()->count(),
            Quota::STATO_PAGATA => $perAnno()->whereNotNull('data_pagamento')->count(),
            Quota::STATO_DA_PAGARE => $perAnno()->whereNull('data_pagamento')->whereDate('data_scadenza', '>', $sogliaInScadenza)->count(),
            Quota::STATO_IN_SCADENZA => $perAnno()->whereNull('data_pagamento')->whereDate('data_scadenza', '>=', $oggi)->whereDate('data_scadenza', '<=', $sogliaInScadenza)->count(),
            Quota::STATO_SCADUTA => $perAnno()->whereNull('data_pagamento')->whereDate('data_scadenza', '<', $oggi)->count(),
        ];

        $associazione = Associazione::query()->find(Quota::currentAssociazioneId());

        return view('quote.index', [
            'kpi' => $kpi,
            'conteggiStato' => $conteggiStato,
            'listino' => TipoSocio::query()->orderBy('nome')->get(['id', 'nome', 'costo_tessera']),
            'costoBase' => $associazione?->costo_tessera,
            'gateway' => $config,
            'quote' => $quote,
            'anni' => $anni,
            'filtroAnno' => $year,
            'filtroStato' => $status,
            'hasStripe' => $hasStripe,
            'hasPayPal' => $hasPayPal,
        ]);
    }

    /**
     * Export CSV delle quote con gli stessi filtri dell'elenco (anno, stato).
     */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('viewAny', Quota::class);

        $year = (string) $request->query('year', 'Tutti');
        $status = (string) $request->query('status', 'Tutti');
        $oggi = now()->startOfDay();

        $query = Quota::query()->with(['socio' => fn ($q) => $q->withTrashed()])->orderBy('anno')->orderBy('data_scadenza');
        if ($year !== 'Tutti' && $year !== '') {
            $query->where('anno', (int) $year);
        }

        $nome = 'quote-'.($year !== 'Tutti' ? $year.'-' : '').now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($query, $status): void {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM: Excel apre correttamente gli accenti
            fputcsv($out, ['Numero socio', 'Cognome', 'Nome', 'Email', 'Tipo', 'Anno', 'Importo', 'Scadenza', 'Stato', 'Data pagamento', 'Metodo'], ';');
            $query->chunk(500, function ($quote) use ($out, $status): void {
                foreach ($quote as $quota) {
                    $stato = $quota->statoCalcolato();
                    if ($status !== 'Tutti' && $status !== $stato) {
                        continue;
                    }
                    fputcsv($out, [
                        $quota->socio?->numero_socio,
                        $quota->socio?->cognome,
                        $quota->socio?->nome,
                        $quota->socio?->email,
                        $quota->tipo,
                        $quota->anno,
                        number_format((float) $quota->importo, 2, ',', ''),
                        $quota->data_scadenza->format('d/m/Y'),
                        $stato,
                        $quota->data_pagamento?->format('d/m/Y'),
                        $quota->metodo_pagamento,
                    ], ';');
                }
            });
            fclose($out);
        }, $nome, ['Content-Type' => 'text/csv; charset=UTF-8']);
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
