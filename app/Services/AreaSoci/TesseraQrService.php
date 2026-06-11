<?php

namespace App\Services\AreaSoci;

use App\Models\Tessera;
use chillerlan\QRCode\Output\QRGdImagePNG;
use chillerlan\QRCode\Output\QRMarkupSVG;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Throwable;

/**
 * Genera i QR code delle tessere come data URI (chillerlan/php-qrcode v6).
 * SVG per il web, PNG (GD) per i PDF dompdf.
 */
class TesseraQrService
{
    /**
     * Payload codificato nel QR: l'URL già salvato sulla tessera
     * (qr_code_url, usato dallo scanner check-in) o il numero tessera.
     */
    public static function payload(Tessera $tessera): string
    {
        return (string) ($tessera->qr_code_url ?: $tessera->numero_tessera);
    }

    public static function svgDataUri(Tessera $tessera): ?string
    {
        try {
            $options = new QROptions([
                'outputInterface' => QRMarkupSVG::class,
                'outputBase64' => true,
                'svgAddXmlHeader' => false,
            ]);

            return (string) (new QRCode($options))->render(self::payload($tessera));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function pngDataUri(Tessera $tessera): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        try {
            $options = new QROptions([
                'outputInterface' => QRGdImagePNG::class,
                'outputBase64' => true,
                'scale' => 4,
            ]);

            return (string) (new QRCode($options))->render(self::payload($tessera));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
