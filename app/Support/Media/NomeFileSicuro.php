<?php

namespace App\Support\Media;

use Illuminate\Http\UploadedFile;

/**
 * Nome casuale per i file caricati, con estensione ricavata dal CONTENUTO
 * (mai da quella scelta dal client) e limitata a un elenco: un file
 * poliglotta chiamato "x.html" non viene mai salvato come .html.
 */
final class NomeFileSicuro
{
    public const IMMAGINI = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * @param  list<string>  $ammesse
     */
    public static function per(UploadedFile $file, string $prefisso, array $ammesse, string $ripiego = 'bin'): string
    {
        $estensione = strtolower((string) $file->guessExtension());

        if (! in_array($estensione, $ammesse, true)) {
            $estensione = $ripiego;
        }

        return $prefisso.bin2hex(random_bytes(12)).'.'.$estensione;
    }
}
