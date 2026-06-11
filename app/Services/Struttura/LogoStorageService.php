<?php

namespace App\Services\Struttura;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Upload del logo associazione (spec struttura §1.3.1, §9.8):
 * nome file rigenerato in modo sicuro con prefisso "logo_",
 * salvataggio su disk public in uploads/logos/.
 */
class LogoStorageService
{
    public function store(UploadedFile $file): string
    {
        $estensione = strtolower($file->getClientOriginalExtension() ?: 'png');
        $nomeFile = 'logo_'.Str::random(32).'.'.$estensione;

        $path = $file->storeAs('uploads/logos', $nomeFile, 'public');

        if ($path === false) {
            throw new \RuntimeException('Salvataggio del logo non riuscito.');
        }

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
