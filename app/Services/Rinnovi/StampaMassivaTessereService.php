<?php

namespace App\Services\Rinnovi;

use App\Models\Associazione;
use App\Services\Struttura\LogoAssociazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Tessere\TesseraPdfRenderer;
use App\Services\Tessere\TesseraQrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stampa massiva tessere (f.4 del piano Vita sociale): un unico PDF
 * multi-tessera (una tessera per pagina, stesso layout A4 del PDF
 * singolo) per la stampa in blocco.
 *
 * Riusa TesseraPdfRenderer (testo template con placeholder risolti)
 * e TesseraQrService per i QR di verifica.
 */
class StampaMassivaTessereService
{
    public function __construct(
        private readonly TesseraPdfRenderer $renderer,
        private readonly TesseraQrService $qr,
    ) {}

    /**
     * Risposta HTTP con il PDF multi-tessera inline.
     *
     * @param  Collection<int, Tessera>  $tessere
     */
    public function render(Associazione $associazione, Collection $tessere): Response
    {
        $logoImg = $this->logoImg($associazione);

        $pagine = $tessere->map(function (Tessera $tessera) use ($associazione) {
            /** @var Socio $socio */
            $socio = $tessera->socio()->withoutGlobalScopes()
                ->with(['tipoSocio', 'categoriaSocio'])
                ->firstOrFail();

            $qrUrl = $tessera->qr_code_url ?: $this->qr->verificationUrl($tessera);
            $qrSvg = $this->qr->generateSvg($qrUrl, 3);

            $costo = $socio->tipoSocio->costo_tessera ?? $associazione->costo_tessera;

            return [
                'tessera' => $tessera,
                'socio' => $socio,
                'qrSvg' => $qrSvg,
                'costoStr' => $costo !== null ? '€ '.number_format((float) $costo, 2, ',', '.') : '',
                'testoTessera' => $this->renderer->testoTessera($tessera, $socio, $associazione, $qrSvg, $qrUrl),
                'validoFinoAl' => $tessera->data_scadenza->format('d/m/Y'),
            ];
        });

        $pdf = Pdf::loadView('rinnovi.stampa-massiva-pdf', [
            'associazione' => $associazione,
            'pagine' => $pagine,
            'logoImg' => $logoImg,
            'dataOggi' => now()->format('d/m/Y'),
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');

        return $pdf->stream('tessere_'.now()->format('Ymd_His').'.pdf');
    }

    /** Tag <img> del logo per dompdf (LogoAssociazione). */
    private function logoImg(Associazione $associazione): string
    {
        return app(LogoAssociazione::class)->imgPdf($associazione);
    }
}
