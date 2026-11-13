<?php

namespace App\Services\Tessere;

use App\Models\Tessera;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Support\Facades\URL;

/**
 * Generazione QR code e URL di verifica tessera.
 *
 * Porta di includes/qrcode_helper.php del legacy, con due differenze:
 * - l'URL è costruito da APP_URL via route firmata (mai dall'header Host);
 * - la firma (HMAC con APP_KEY) è la capability per leggere i dati della
 *   tessera: il solo UUID non basta, perché è prevedibile o recuperabile da
 *   altre pagine. La stessa firma del QR vale per la pagina HTML, per
 *   l'endpoint JSON dello scanner e per /api/v1/tessere/verify.
 */
class TesseraQrService
{
    /**
     * SVG raw (non base64) del QR, ideale per l'embed in Dompdf.
     */
    public function generateSvg(string $data, int $scale = 3): string
    {
        $options = new QROptions([
            'outputInterface' => QRMarkupSVG::class,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'outputBase64' => false,
            'addQuietzone' => true,
            'svgUseFillAttributes' => true,
        ]);

        return (new QRCode($options))->render($data);
    }

    /**
     * PNG come data URI base64, per i tag <img> HTML (stampa card).
     */
    public function generateDataUri(string $data, int $scale = 3): string
    {
        $options = new QROptions([
            'outputInterface' => QRGdImagePNG::class,
            'eccLevel' => EccLevel::M,
            'scale' => $scale,
            'outputBase64' => true,
            'addQuietzone' => true,
        ]);

        return (new QRCode($options))->render($data);
    }

    /**
     * URL pubblico (firmato) di verifica della tessera, basato su APP_URL.
     */
    public function verificationUrl(Tessera|string $tessera): string
    {
        $id = $tessera instanceof Tessera ? $tessera->id : strtolower($tessera);

        return URL::signedRoute('tessere.verifica', ['tessera' => $id]);
    }

    /**
     * Firma della tessera: il parametro `signature` dell'URL di verifica
     * (quello stampato nel QR).
     */
    public function signature(Tessera|string $tessera): string
    {
        $query = (string) parse_url($this->verificationUrl($tessera), PHP_URL_QUERY);
        parse_str($query, $params);

        return (string) ($params['signature'] ?? '');
    }

    /**
     * Compatibilità: una tessera importata dal gestionale precedente può avere
     * stampato un QR con l'URL di verifica senza firma (qr_code_url legacy).
     * Per quelle sole tessere il solo UUID resta accettato; le tessere emesse
     * da questa applicazione hanno sempre l'URL firmato.
     */
    public function qrLegacySenzaFirma(Tessera $tessera): bool
    {
        $url = (string) ($tessera->qr_code_url ?? '');

        return $url !== '' && ! str_contains($url, 'signature=');
    }

    /**
     * URL da stampare nel QR: quello persistito se già firmato, altrimenti
     * l'URL firmato corrente. Così ogni ristampa di una tessera legacy porta
     * la firma e, dopo tessere:firma-qr, il solo UUID non basta più.
     */
    public function urlPerStampa(Tessera $tessera): string
    {
        $url = (string) ($tessera->qr_code_url ?? '');

        return $url !== '' && str_contains($url, 'signature=') ? $url : $this->verificationUrl($tessera);
    }

    /**
     * Vero se la firma presentata è quella dell'URL di verifica della tessera.
     * Il confronto è a tempo costante e non tocca il database: si può fare
     * prima della lookup, così la risposta non rivela se l'UUID esiste.
     */
    public function firmaValida(string $tesseraId, mixed $signature): bool
    {
        if (! is_string($signature) || $signature === '') {
            return false;
        }

        return hash_equals($this->signature($tesseraId), $signature);
    }
}
