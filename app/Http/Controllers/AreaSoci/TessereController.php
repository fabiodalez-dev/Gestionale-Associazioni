<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\AreaSoci\TesseraQrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class TessereController extends Controller
{
    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $tessere = $socio->tessere()
            ->orderByDesc('anno_validita')
            ->orderByDesc('data_emissione')
            ->get();

        return view('area-soci.tessere', [
            'socio' => $socio,
            'tessere' => $tessere,
            'statiCalcolati' => $tessere->mapWithKeys(
                fn (Tessera $t) => [$t->id => $this->statoCalcolato($t)]
            ),
        ]);
    }

    /**
     * Download PDF della tessera. Miglioramento rispetto al legacy (file
     * statico in uploads/ senza access-control): il file viene servito dallo
     * storage privato SOLO al socio proprietario; se non esiste viene
     * generato al volo con dompdf (QR incluso).
     */
    public function pdf(Request $request, string $tesseraId): Response
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        /** @var Tessera $tessera */
        $tessera = $socio->tessere()->findOrFail($tesseraId);

        $filename = $this->nomeFilePdf($socio, $tessera);

        // basename() al punto d'uso: il path non può uscire da documents/
        $percorso = 'documents/'.basename($filename);

        if (Storage::disk('local')->exists($percorso)) {
            return Storage::disk('local')->download($percorso, $filename);
        }

        $pdf = Pdf::loadView('area-soci.pdf.tessera', [
            'socio' => $socio,
            'tessera' => $tessera,
            'associazione' => $socio->associazione,
            'qrPng' => TesseraQrService::pngDataUri($tessera),
            'statoCalcolato' => $this->statoCalcolato($tessera),
        ])->setPaper('a5', 'landscape');

        return $pdf->download($filename);
    }

    /**
     * Convenzione legacy come chiave di lookup: tessera_{numero_socio}_{anno}.pdf.
     * Il nome file è ricostruito SOLO da caratteri whitelistati ([A-Za-z0-9_-])
     * e dall'anno castato a intero: nessun input utente raggiunge il path e
     * basename() esclude per costruzione qualsiasi attraversamento di directory.
     */
    private function nomeFilePdf(Socio $socio, Tessera $tessera): string
    {
        $numeroSocio = (string) preg_replace('/[^A-Za-z0-9_-]/', '', (string) $socio->numero_socio);

        return basename(sprintf('tessera_%s_%d.pdf', $numeroSocio, (int) $tessera->anno_validita));
    }

    /**
     * Stato visualizzato nel portale (spec 8.8): se lo stato DB non è 'Attiva'
     * vale quello; altrimenti è derivato da data_scadenza (finestra 30gg).
     */
    private function statoCalcolato(Tessera $tessera): string
    {
        if ($tessera->stato !== Tessera::STATO_ATTIVA) {
            return (string) $tessera->stato;
        }

        $oggi = now()->startOfDay();

        if ($tessera->data_scadenza->lt($oggi)) {
            return Tessera::STATO_SCADUTA;
        }

        if ($tessera->data_scadenza->lte($oggi->copy()->addDays(30))) {
            return 'In Scadenza';
        }

        return Tessera::STATO_ATTIVA;
    }
}
