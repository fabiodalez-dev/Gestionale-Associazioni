<?php

namespace App\Http\Controllers\Rinnovi;

use App\Exports\StoricoTesseramentoExport;
use App\Http\Controllers\Controller;
use App\Models\Socio;
use App\Services\Rinnovi\StoricoTesseramentoService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Export Excel dello storico tesseramento del singolo socio (f.6).
 * La timeline è mostrata nella scheda socio (tab "Tesseramento");
 * l'isolamento tenant è garantito dal global scope sul route binding.
 */
class StoricoTesseramentoController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private readonly StoricoTesseramentoService $storico) {}

    public function export(Socio $socio): BinaryFileResponse
    {
        $this->authorize('view', $socio);

        $slug = Str::slug($socio->cognome.'-'.$socio->nome) ?: 'socio';

        return Excel::download(
            new StoricoTesseramentoExport($socio, $this->storico),
            'storico-tesseramento_'.$slug.'_'.now()->format('Ymd_His').'.xlsx'
        );
    }
}
