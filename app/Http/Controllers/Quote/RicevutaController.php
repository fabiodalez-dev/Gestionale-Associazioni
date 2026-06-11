<?php

namespace App\Http\Controllers\Quote;

use App\Http\Controllers\Controller;
use App\Models\Quota;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ricevuta di pagamento in PDF (dompdf) — area amministrativa.
 */
class RicevutaController extends Controller
{
    use AuthorizesRequests;

    public function show(Quota $quota): Response|RedirectResponse
    {
        $this->authorize('view', $quota);

        if (! $quota->isPagata()) {
            return redirect()->route('quote.index')
                ->with('error', 'La ricevuta è disponibile solo per quote pagate.');
        }

        $quota->loadMissing(['associazione', 'socio' => fn ($q) => $q->withTrashed()]);

        $pdf = Pdf::loadView('quote.ricevuta-pdf', ['quota' => $quota]);

        return $pdf->download("ricevuta-quota-{$quota->anno}-{$quota->id}.pdf");
    }
}
