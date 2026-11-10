<?php

namespace App\Services\FrontendPubblico;

use App\Models\Associazione;
use App\Models\Evento;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Immagine 1200×630 per l'anteprima di un evento sui social e su WhatsApp
 * (#43), quando l'evento non ha una copertina: titolo, data, luogo e nome
 * dell'associazione nel colore del mini-sito. Generata con GD e salvata in
 * cache; cambia nome (e quindi si rigenera) quando cambia l'evento.
 */
class ImmagineSocialEvento
{
    public const LARGHEZZA = 1200;

    public const ALTEZZA = 630;

    public function percorso(Evento $evento, Associazione $associazione): string
    {
        $moduli = $associazione->moduli();
        $impronta = substr(sha1(implode('|', [
            $evento->titolo, $evento->data_evento->toIso8601String(), $evento->luogo,
            $associazione->nome, $moduli->colore_primario, $evento->updated_at?->getTimestamp(),
        ])), 0, 16);

        $cartella = storage_path('app/immagini-social');
        $file = $cartella.'/'.$evento->id.'-'.$impronta.'.png';
        if (is_file($file)) {
            return $file;
        }

        File::ensureDirectoryExists($cartella);
        // Versioni precedenti dello stesso evento
        foreach (glob($cartella.'/'.$evento->id.'-*.png') ?: [] as $vecchio) {
            @unlink($vecchio);
        }

        $this->disegna($evento, $associazione, $file);

        return $file;
    }

    private function disegna(Evento $evento, Associazione $associazione, string $file): void
    {
        $font = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
        $grassetto = base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
        if (! is_file($font) || ! is_file($grassetto) || ! function_exists('imagettftext')) {
            throw new RuntimeException('Font o FreeType non disponibili per l\'immagine social.');
        }

        $moduli = $associazione->moduli();
        [$r, $g, $b] = array_map('intval', explode(',', str_replace(' ', '', $moduli->coloreRgb())));
        $chiaro = $moduli->coloreTesto() === '#FFFFFF';

        $img = imagecreatetruecolor(self::LARGHEZZA, self::ALTEZZA);
        $sfondo = (int) imagecolorallocate($img, $r, $g, $b);
        $testo = $chiaro ? (int) imagecolorallocate($img, 255, 255, 255) : (int) imagecolorallocate($img, 31, 41, 55);
        $tenue = $chiaro ? (int) imagecolorallocatealpha($img, 255, 255, 255, 35) : (int) imagecolorallocatealpha($img, 31, 41, 55, 35);
        imagefilledrectangle($img, 0, 0, self::LARGHEZZA, self::ALTEZZA, $sfondo);

        $margine = 80;
        $y = 120;
        imagettftext($img, 28, 0, $margine, $y, $tenue, $grassetto, Str::limit($associazione->nome, 60));

        // Titolo a capo su più righe (al massimo 3)
        $y += 50;
        $righe = $this->righe((string) $evento->titolo, $grassetto, 60, self::LARGHEZZA - 2 * $margine);
        if (count($righe) > 3) {
            // Titolo troppo lungo: tre righe, l'ultima con i puntini
            $righe = array_slice($righe, 0, 3);
            $parole = explode(' ', $righe[2]);
            array_pop($parole);
            $righe[2] = implode(' ', $parole).' …';
        }
        foreach ($righe as $riga) {
            $y += 80;
            imagettftext($img, 60, 0, $margine, $y, $testo, $grassetto, $riga);
        }

        $quando = $evento->data_evento->locale('it')->translatedFormat('l j F Y, \o\r\e H:i');
        imagettftext($img, 32, 0, $margine, self::ALTEZZA - 130, $testo, $font, Str::ucfirst($quando));
        if (filled($evento->luogo)) {
            imagettftext($img, 30, 0, $margine, self::ALTEZZA - 75, $tenue, $font, Str::limit((string) $evento->luogo, 60));
        }

        imagepng($img, $file, 6);
        imagedestroy($img);
    }

    /**
     * Spezza il testo in righe che stanno nella larghezza indicata.
     *
     * @return list<string>
     */
    private function righe(string $testo, string $font, int $corpo, int $larghezza): array
    {
        $righe = [];
        $riga = '';
        foreach (preg_split('/\s+/u', trim($testo)) ?: [] as $parola) {
            $prova = $riga === '' ? $parola : $riga.' '.$parola;
            $box = imagettfbbox($corpo, 0, $font, $prova);
            if ($box !== false && ($box[2] - $box[0]) > $larghezza && $riga !== '') {
                $righe[] = $riga;
                $riga = $parola;
            } else {
                $riga = $prova;
            }
        }
        if ($riga !== '') {
            $righe[] = $riga;
        }

        return $righe;
    }
}
