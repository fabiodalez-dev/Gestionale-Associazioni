<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Models\Quota;
use App\Services\Pagamenti\RicevutaService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ricevuta di pagamento in PDF (dompdf) — area amministrativa.
 *
 * Dal piano Vita sociale (f.21) la ricevuta è NUMERATA per anno
 * (tabella ricevute, una per quota): per le quote pagate prima della
 * funzionalità viene emessa on demand al primo download (idempotente).
 */
class RicevutaController extends Controller
{
    use AuthorizesRequests;

    public function show(Quota $quota, RicevutaService $ricevute): Response|RedirectResponse
    {
        $this->authorize('view', $quota);

        if (! $quota->isPagata()) {
            return redirect()->route('quote.index')
                ->with('error', 'La ricevuta è disponibile solo per quote pagate.');
        }

        $ricevuta = $ricevute->emettiPerQuota($quota);

        if ($ricevuta === null) {
            return redirect()->route('quote.index')
                ->with('error', 'Impossibile generare la ricevuta per questa quota.');
        }

        return response($ricevute->pdfContent($ricevuta), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$ricevute->nomeFile($ricevuta).'"',
        ]);
    }
}
