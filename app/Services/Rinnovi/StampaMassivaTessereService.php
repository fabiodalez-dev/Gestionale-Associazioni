<?php

namespace App\Services\Rinnovi;

use App\Models\Associazione;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Struttura\LogoAssociazione;
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
     * Formati (#47): 'a4' una tessera per pagina A4 (documento), 'cr80' una
     * tessera per pagina in formato carta di credito (85,6 × 54 mm, per le
     * stampanti di card), 'biglietti' fogli A4 da 10 tessere con linee di
     * taglio (fogli per biglietti da visita).
     *
     * @param  Collection<int, Tessera>  $tessere
     */
    public function render(Associazione $associazione, Collection $tessere, string $formato = 'a4'): Response
    {
        $logoImg = $this->logoImg($associazione);

        $pagine = $tessere->map(function (Tessera $tessera) use ($associazione, $formato) {
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
                // PNG per i formati card (#47): dompdf disegna in modo affidabile le immagini, non sempre gli SVG inline
                'qrImg' => $formato === 'a4' ? null : $this->qr->generateDataUri($qrUrl, 3),
                'costoStr' => $costo !== null ? '€ '.number_format((float) $costo, 2, ',', '.') : '',
                'testoTessera' => $this->renderer->testoTessera($tessera, $socio, $associazione, $qrSvg, $qrUrl),
                'validoFinoAl' => $tessera->data_scadenza->format('d/m/Y'),
            ];
        });

        $vista = $formato === 'a4' ? 'rinnovi.stampa-massiva-pdf' : 'rinnovi.stampa-tessere-card-pdf';
        // CR80: 85,6 × 54 mm in punti tipografici (1 mm = 72/25,4 pt), dati
        // come lato corto × lato lungo: in "landscape" dompdf li mette in orizzontale
        $carta = $formato === 'cr80' ? [0, 0, 153.07, 242.65] : 'a4';

        $pdf = Pdf::loadView($vista, [
            'associazione' => $associazione,
            'pagine' => $pagine,
            'logoImg' => $logoImg,
            'dataOggi' => now()->format('d/m/Y'),
            'formato' => $formato,
            'fogli' => $pagine->chunk(10),
        ])
            // La CR80 è più larga che alta: in "portrait" dompdf scambierebbe i lati
            ->setPaper($carta, $formato === 'cr80' ? 'landscape' : 'portrait')
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
