<?php

namespace App\Console\Commands;

use App\Models\Associazione;
use App\Services\Rinnovi\AperturaAnnoService;
use Illuminate\Console\Command;

/**
 * Apertura anno sociale da CLI (f.5 del piano Vita sociale): genera in
 * bulk le quote dell'anno per i soci Attivi (idempotente: salta i soci
 * che hanno già una quota per l'anno). Default: anno prossimo, tutti i
 * tenant attivi. Con --dry-run mostra solo l'anteprima.
 */
class RinnoviApriAnno extends Command
{
    protected $signature = 'rinnovi:apri-anno
                            {anno? : Anno sociale da aprire (default: anno prossimo)}
                            {--associazione= : Limita l\'apertura a un solo tenant (UUID)}
                            {--dry-run : Mostra solo l\'anteprima senza generare le quote}';

    protected $description = 'Apre l\'anno sociale generando in bulk le quote per i soci attivi';

    public function handle(AperturaAnnoService $apertura): int
    {
        $anno = (int) ($this->argument('anno') ?: now()->year + 1);

        if ($anno < 2000 || $anno > 2100) {
            $this->error("Anno non valido: {$anno}");

            return self::FAILURE;
        }

        $query = Associazione::query()->where('attiva', true);

        if ($id = $this->option('associazione')) {
            $query->whereKey($id);
        }

        $totale = 0;

        foreach ($query->orderBy('nome')->cursor() as $associazione) {
            $anteprima = $apertura->anteprima($associazione, $anno);

            if ($this->option('dry-run')) {
                $this->line(sprintf(
                    '%s — soci attivi: %d, già aperte: %d, da generare: %d (totale € %s)',
                    $associazione->nome,
                    $anteprima['soci_attivi'],
                    $anteprima['gia_aperte'],
                    $anteprima['da_generare'],
                    number_format($anteprima['importo_totale'], 2, ',', '.')
                ));

                continue;
            }

            $create = $apertura->apri($associazione, $anno);
            $totale += $create;

            $this->line("{$associazione->nome}: {$create} quote generate per l'anno {$anno}.");
        }

        if (! $this->option('dry-run')) {
            $this->info("Apertura anno {$anno} completata: {$totale} quote generate.");
        }

        return self::SUCCESS;
    }
}
