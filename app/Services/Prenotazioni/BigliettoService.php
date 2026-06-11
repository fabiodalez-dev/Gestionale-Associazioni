<?php

namespace App\Services\Prenotazioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Services\Tessere\TesseraQrService;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfBuilder;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Biglietto della prenotazione: URL firmato di verifica, QR (SVG/data URI)
 * e PDF (dompdf) con brand dell'associazione, dati evento, nominativo,
 * posti, codice PRN e QR grande.
 *
 * Il QR contiene l'URL firmato di /verifica-biglietto/{qr_token}: la firma
 * (APP_KEY) rende il biglietto tamper-evident, il token (64 hex, unique
 * globale) è il capability token della prenotazione.
 */
class BigliettoService
{
    public function __construct(private readonly TesseraQrService $qr) {}

    /**
     * URL pubblico firmato di verifica del biglietto (basato su APP_URL).
     */
    public function signedUrl(Prenotazione $prenotazione): string
    {
        return URL::signedRoute('biglietti.verifica', ['token' => $prenotazione->qr_token]);
    }

    /**
     * SVG raw del QR del biglietto (ideale per embed in Dompdf e inline HTML).
     */
    public function qrSvg(Prenotazione $prenotazione, int $scale = 3): string
    {
        return $this->qr->generateSvg($this->signedUrl($prenotazione), $scale);
    }

    /**
     * PNG come data URI base64, per i tag <img> (QR inline area riservata).
     */
    public function qrDataUri(Prenotazione $prenotazione, int $scale = 3): string
    {
        return $this->qr->generateDataUri($this->signedUrl($prenotazione), $scale);
    }

    /**
     * Risposta HTTP con il PDF del biglietto inline (biglietto_{codice}.pdf).
     */
    public function pdfResponse(Prenotazione $prenotazione): Response
    {
        return $this->pdf($prenotazione)->stream($this->pdfFilename($prenotazione));
    }

    /**
     * Contenuto binario del PDF (per gli allegati email delle notifiche).
     */
    public function pdfBinary(Prenotazione $prenotazione): string
    {
        return $this->pdf($prenotazione)->output();
    }

    public function pdfFilename(Prenotazione $prenotazione): string
    {
        return 'biglietto_'.str_replace('-', '_', $prenotazione->codice).'.pdf';
    }

    /**
     * Builder dompdf del biglietto. Le relazioni sono caricate senza global
     * scope tenant: il PDF può essere generato anche fuori dal contesto del
     * tenant (es. reinvio da admin, job di notifica in coda).
     */
    private function pdf(Prenotazione $prenotazione): PdfBuilder
    {
        /** @var Evento $evento */
        $evento = $prenotazione->evento()->withoutGlobalScope('associazione')->firstOrFail();
        /** @var Account $account */
        $account = $prenotazione->account()->withoutGlobalScope('associazione')->firstOrFail();
        /** @var Associazione $associazione */
        $associazione = $prenotazione->associazione()->firstOrFail();

        return Pdf::loadView('pubblico.biglietti.pdf', [
            'prenotazione' => $prenotazione,
            'evento' => $evento,
            'account' => $account,
            'associazione' => $associazione,
            'qrSvg' => $this->qrSvg($prenotazione, 4),
            'logoImg' => $this->logoImg($associazione),
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('isRemoteEnabled', true)
            ->setOption('defaultFont', 'DejaVu Sans');
    }

    /**
     * Tag <img> del logo per il PDF: URL remoto così com'è, path locale
     * convertito in data URI (con protezione path traversal confinata a
     * public/), come TesseraPdfRenderer.
     */
    private function logoImg(Associazione $associazione): string
    {
        $logo = $associazione->logo_url;

        if (empty($logo)) {
            return '';
        }

        if (preg_match('~^https?://~i', $logo)) {
            return '<img src="'.e($logo).'" style="height:48px; width:auto;">';
        }

        $root = realpath(public_path());
        $path = realpath(public_path(ltrim($logo, '/')));

        if ($root === false || $path === false || ! is_file($path) || ! str_starts_with($path, $root)) {
            return '';
        }

        $mime = mime_content_type($path) ?: 'image/png';
        $data = base64_encode((string) file_get_contents($path));

        return '<img src="data:'.e($mime).';base64,'.$data.'" style="height:48px; width:auto;">';
    }
}
