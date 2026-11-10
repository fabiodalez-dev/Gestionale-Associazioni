<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;
use RuntimeException;

/**
 * Foto del socio pronta per la tessera (#48), come la foto del lettore di
 * Pinakes: raddrizzata secondo l'orientamento EXIF (le foto dei telefoni
 * arrivano spesso ruotate), ritagliata al centro in 4:5, ridotta a 480×600 e
 * ricodificata in JPEG. La ricodifica con GD non copia i metadati: la
 * posizione GPS e gli altri dati EXIF non restano sul server.
 */
final class FotoTessera
{
    public const LARGHEZZA = 480;

    public const ALTEZZA = 600;

    /** Percorso di un JPEG temporaneo normalizzato (da passare a medialibrary). */
    public static function normalizza(UploadedFile $file): string
    {
        $percorso = (string) $file->getRealPath();
        $dati = @file_get_contents($percorso);
        $immagine = $dati === false ? false : @imagecreatefromstring($dati);
        if ($immagine === false) {
            throw new RuntimeException('Immagine non leggibile.');
        }

        $immagine = self::raddrizza($immagine, $percorso);

        $larghezza = imagesx($immagine);
        $altezza = imagesy($immagine);
        $rapporto = self::LARGHEZZA / self::ALTEZZA;

        // Ritaglio centrale al rapporto 4:5
        if ($larghezza / $altezza > $rapporto) {
            $l = (int) round($altezza * $rapporto);
            [$x, $y, $l, $h] = [intdiv($larghezza - $l, 2), 0, $l, $altezza];
        } else {
            $h = (int) round($larghezza / $rapporto);
            [$x, $y, $l, $h] = [0, intdiv($altezza - $h, 2), $larghezza, $h];
        }

        $finale = imagecreatetruecolor(self::LARGHEZZA, self::ALTEZZA);
        // Sfondo bianco per i PNG con trasparenza
        imagefill($finale, 0, 0, (int) imagecolorallocate($finale, 255, 255, 255));
        imagecopyresampled($finale, $immagine, 0, 0, $x, $y, self::LARGHEZZA, self::ALTEZZA, $l, $h);
        imagedestroy($immagine);

        $base = (string) tempnam(sys_get_temp_dir(), 'foto-');
        @unlink($base);
        $destinazione = $base.'.jpg';
        imagejpeg($finale, $destinazione, 85);
        imagedestroy($finale);

        return $destinazione;
    }

    private static function raddrizza(\GdImage $immagine, string $percorso): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $immagine;
        }

        $exif = @exif_read_data($percorso);
        $orientamento = is_array($exif) ? (int) ($exif['Orientation'] ?? 1) : 1;

        $ruotata = match ($orientamento) {
            3 => imagerotate($immagine, 180, 0),
            6 => imagerotate($immagine, -90, 0),
            8 => imagerotate($immagine, 90, 0),
            default => $immagine,
        };

        return $ruotata === false ? $immagine : $ruotata;
    }
}
