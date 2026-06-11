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
 * - la firma rende il QR tamper-evident (l'UUID resta comunque il capability
 *   token: l'inserimento manuale nello scanner funziona senza firma).
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
        $id = $tessera instanceof Tessera ? $tessera->id : $tessera;

        return URL::signedRoute('tessere.verifica', ['tessera' => $id]);
    }
}
