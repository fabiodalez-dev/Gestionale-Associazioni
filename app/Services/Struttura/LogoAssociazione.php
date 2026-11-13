<?php

namespace App\Services\Struttura;

use App\Models\Associazione;
use App\Support\Media\NomeFileSicuro;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Logo dell'associazione su medialibrary (collezione "logo", disco public,
 * conversioni "miniatura" e "email"). Unico punto per salvarlo e per
 * ottenerne URL assoluto (pagine pubbliche, email) o file locale (PDF).
 *
 * Loghi precedenti senza media (colonna logo_url): URL assoluto così com'è,
 * oppure percorso sul disco public (upload della nuova app) o sotto public/
 * (installazioni migrate dalla vecchia app). `app:migra-media` li importa.
 */
class LogoAssociazione
{
    public const COLLEZIONE = 'logo';

    public function salva(Associazione $associazione, UploadedFile $file): void
    {
        $vecchio = $associazione->getFirstMedia(self::COLLEZIONE) === null ? $associazione->logo_url : null;

        $media = $associazione->addMedia($file)
            ->usingFileName(NomeFileSicuro::per($file, 'logo-', NomeFileSicuro::IMMAGINI, 'png'))
            ->toMediaCollection(self::COLLEZIONE);

        // Colonna allineata finché esiste (onboarding, export, codice esterno)
        $associazione->forceFill(['logo_url' => $media->getPathRelativeToRoot()])->save();

        if (filled($vecchio) && ! preg_match('~^https?://~i', (string) $vecchio)) {
            Storage::disk('public')->delete((string) $vecchio);
        }
    }

    /** URL assoluto del logo (o della conversione), null se assente. */
    public function url(Associazione $associazione, string $conversione = ''): ?string
    {
        $media = $associazione->getFirstMedia(self::COLLEZIONE);
        if ($media !== null) {
            return $this->haConversione($media, $conversione) ? $media->getFullUrl($conversione) : $media->getFullUrl();
        }

        $logo = (string) $associazione->logo_url;
        if ($logo === '') {
            return null;
        }
        if (preg_match('~^https?://~i', $logo)) {
            return $logo;
        }
        if ($this->fileLegacy($logo) === null) {
            return null;
        }

        $percorso = ltrim($logo, '/');

        return Storage::disk('public')->exists($percorso)
            ? Storage::disk('public')->url($percorso)
            : rtrim((string) config('app.url'), '/').'/'.$percorso;
    }

    /** File locale del logo (per i PDF), null se assente o remoto. */
    public function percorso(Associazione $associazione, string $conversione = ''): ?string
    {
        $media = $associazione->getFirstMedia(self::COLLEZIONE);
        if ($media !== null) {
            $path = $this->haConversione($media, $conversione) ? $media->getPath($conversione) : $media->getPath();

            return is_file($path) ? $path : null;
        }

        $logo = (string) $associazione->logo_url;

        return $logo === '' || preg_match('~^https?://~i', $logo) ? null : $this->fileLegacy($logo);
    }

    /**
     * Tag <img> per dompdf: sempre e solo un file locale in data URI (dompdf
     * gira con isRemoteEnabled=false, quindi nessun fetch remoto). Un
     * logo_url legacy http(s) viene usato solo se punta a un file di questa
     * installazione (APP_URL o URL del disco public); altrimenti è ignorato.
     */
    public function imgPdf(Associazione $associazione, string $stile = 'height:48px; width:auto;'): string
    {
        $percorso = $this->percorso($associazione) ?? $this->fileDaUrlLocale((string) $associazione->logo_url);
        if ($percorso === null) {
            return '';
        }

        $mime = mime_content_type($percorso) ?: 'image/png';

        return '<img src="data:'.e($mime).';base64,'.base64_encode((string) file_get_contents($percorso)).'" style="'.e($stile).'">';
    }

    /**
     * File locale corrispondente a un URL http(s) di questa installazione
     * (prefisso APP_URL o URL del disco public), null se l'URL è esterno o
     * il file non esiste.
     */
    public function fileDaUrlLocale(string $url): ?string
    {
        if (! preg_match('~^https?://~i', $url)) {
            return null;
        }

        $radici = [
            Storage::disk('public')->url(''),
            (string) config('app.url'),
        ];

        foreach ($radici as $radice) {
            $radice = rtrim($radice, '/').'/';
            if ($radice === '/' || ! str_starts_with(strtolower($url), strtolower($radice))) {
                continue;
            }

            $relativo = (string) parse_url(substr($url, strlen($radice)), PHP_URL_PATH);
            $file = $this->fileLegacy(rawurldecode($relativo));
            if ($file !== null) {
                return $file;
            }
        }

        return null;
    }

    private function haConversione(Media $media, string $conversione): bool
    {
        return $conversione !== '' && $media->hasGeneratedConversion($conversione);
    }

    /**
     * Percorso di un logo legacy, confinato al disco public o a public/
     * (protezione da path traversal).
     */
    public function fileLegacy(string $logo): ?string
    {
        $relativo = ltrim($logo, '/');

        foreach ([Storage::disk('public')->path(''), public_path()] as $radice) {
            $root = realpath($radice);
            $path = realpath(rtrim($radice, '/').'/'.$relativo);

            if ($root !== false && $path !== false && is_file($path) && str_starts_with($path, $root.DIRECTORY_SEPARATOR)) {
                return $path;
            }
        }

        return null;
    }
}
