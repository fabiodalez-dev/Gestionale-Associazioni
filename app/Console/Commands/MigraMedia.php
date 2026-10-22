<?php

namespace App\Console\Commands;

use App\Models\Associazione;
use App\Models\Documento;
use App\Models\DocumentoSocio;
use App\Services\Struttura\LogoAssociazione;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Throwable;

/**
 * Porta su medialibrary i file caricati prima della Fase 6: loghi delle
 * associazioni (copiati: il vecchio file resta dov'è), documenti dei soci e
 * documenti dell'archivio (spostati nella cartella del media, percorso_file
 * aggiornato). Idempotente: salta i record che hanno già un media.
 */
class MigraMedia extends Command
{
    protected $signature = 'app:migra-media {--dry-run : Mostra cosa verrebbe migrato senza modificare nulla}';

    protected $description = 'Importa in medialibrary loghi e documenti caricati con il sistema precedente';

    /** @var array<string, array{migrati: int, mancanti: int, errori: int}> */
    private array $esito = [];

    public function handle(LogoAssociazione $logo): int
    {
        $prova = (bool) $this->option('dry-run');

        Associazione::query()->whereNotNull('logo_url')->where('logo_url', '!=', '')
            ->whereDoesntHave('media', fn ($q) => $q->where('collection_name', LogoAssociazione::COLLEZIONE))
            ->lazyById()
            ->each(function (Associazione $associazione) use ($logo, $prova): void {
                $valore = (string) $associazione->logo_url;
                if (preg_match('~^https?://~i', $valore)) {
                    return; // URL esterno: resta com'è
                }
                $percorso = $logo->fileLegacy($valore);

                $this->migra('Loghi', $percorso, $prova, function () use ($associazione, $percorso): void {
                    $media = $associazione->addMedia((string) $percorso)->preservingOriginal()
                        ->toMediaCollection(LogoAssociazione::COLLEZIONE);
                    $associazione->forceFill(['logo_url' => $media->getPathRelativeToRoot()])->save();
                }, $associazione->nome);
            });

        foreach ([DocumentoSocio::class => 'Documenti soci', Documento::class => 'Documenti archivio'] as $classe => $etichetta) {
            $classe::query()->withoutGlobalScopes()->withTrashed()
                ->where('percorso_file', '!=', '')
                ->whereDoesntHave('media')
                ->lazyById()
                ->each(function (Model&HasMedia $documento) use ($etichetta, $prova): void {
                    $relativo = (string) $documento->getAttribute('percorso_file');
                    $percorso = Storage::disk('local')->exists($relativo) ? Storage::disk('local')->path($relativo) : null;

                    $this->migra($etichetta, $percorso, $prova, function () use ($documento, $percorso): void {
                        $media = $documento->addMedia((string) $percorso)
                            ->usingName((string) $documento->getAttribute('nome_file'))
                            ->toMediaCollection('file');
                        $documento->forceFill(['percorso_file' => $media->getPathRelativeToRoot()])->saveQuietly();
                    }, (string) $documento->getAttribute('nome_file'));
                });
        }

        if ($this->esito === []) {
            $this->info('Niente da migrare.');

            return self::SUCCESS;
        }

        $this->table(['Tipo', $prova ? 'Da migrare' : 'Migrati', 'File mancanti', 'Errori'], collect($this->esito)
            ->map(fn (array $r, string $tipo) => [$tipo, $r['migrati'], $r['mancanti'], $r['errori']])->values()->all());

        return collect($this->esito)->sum('errori') > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function migra(string $tipo, ?string $percorso, bool $prova, callable $azione, string $descrizione): void
    {
        $this->esito[$tipo] ??= ['migrati' => 0, 'mancanti' => 0, 'errori' => 0];

        if ($percorso === null) {
            $this->esito[$tipo]['mancanti']++;
            $this->warn("{$tipo}: file non trovato per «{$descrizione}»");

            return;
        }

        if ($prova) {
            $this->esito[$tipo]['migrati']++;

            return;
        }

        try {
            $azione();
            $this->esito[$tipo]['migrati']++;
        } catch (Throwable $e) {
            $this->esito[$tipo]['errori']++;
            $this->error("{$tipo}: «{$descrizione}» non migrato: {$e->getMessage()}");
        }
    }
}
