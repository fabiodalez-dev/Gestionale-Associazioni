<?php

namespace App\Console\Commands;

use App\Models\Documento;
use App\Models\Evento;
use App\Models\Socio;
use App\Models\Verbale;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Svuotamento automatico del cestino unificato (f.24 del piano "Gestione 2"):
 * elimina DEFINITIVAMENTE i soft-deleted di soci/eventi/documenti/verbali
 * più vecchi di 90 giorni (configurabile con --giorni), su tutti i tenant.
 *
 * Il forceDelete avviene record per record per innescare gli eventi del
 * model (es. pulizia dei file medialibrary dei documenti).
 *
 * La schedulazione (routes/console.php) è demandata a Rifiniture.
 */
class CestinoSvuota extends Command
{
    protected $signature = 'cestino:svuota {--giorni=90 : Età minima (in giorni) degli elementi da eliminare}';

    protected $description = 'Elimina definitivamente gli elementi nel cestino da oltre N giorni (default 90)';

    /** @var array<string, class-string<Model>> */
    private const MODELLI = [
        'soci' => Socio::class,
        'eventi' => Evento::class,
        'documenti' => Documento::class,
        'verbali' => Verbale::class,
    ];

    public function handle(): int
    {
        $giorni = max(1, (int) $this->option('giorni'));
        $limite = now()->subDays($giorni);
        $totale = 0;

        foreach (self::MODELLI as $nome => $model) {
            $eliminati = 0;

            // In console il global scope tenant non filtra (nessun utente né
            // sessione), ma lo si rimuove esplicitamente per chiarezza.
            $model::onlyTrashed()
                ->withoutGlobalScope('associazione')
                ->where('deleted_at', '<', $limite)
                ->orderBy('deleted_at')
                ->get()
                ->each(function (Model $record) use (&$eliminati): void {
                    $record->forceDelete();
                    $eliminati++;
                });

            $totale += $eliminati;
            $this->info("{$nome}: {$eliminati} eliminati definitivamente");
        }

        $this->info("Totale elementi rimossi dal cestino (oltre {$giorni} giorni): {$totale}");

        return self::SUCCESS;
    }
}
