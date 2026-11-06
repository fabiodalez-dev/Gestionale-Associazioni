<?php

namespace App\Console\Commands;

use App\Services\Tessere\VincoloTessereAnnoService;
use Illuminate\Console\Command;

/**
 * Aggiunge il vincolo "una tessera non annullata per socio e anno" se la
 * migration l'aveva rinviato per tessere doppie già presenti e i doppioni
 * sono stati sistemati. Idempotente: pianificato ogni notte, nessuna
 * modifica alle tessere oltre all'allineamento della colonna del vincolo.
 */
class TessereApplicaVincoloAnno extends Command
{
    protected $signature = 'tessere:applica-vincolo-anno';

    protected $description = 'Applica il vincolo di unicità socio/anno sulle tessere appena non ci sono più doppioni';

    public function handle(VincoloTessereAnnoService $vincolo): int
    {
        if ($vincolo->attivo()) {
            $this->info('Vincolo già attivo.');

            return self::SUCCESS;
        }

        if ($vincolo->applicaSePossibile()) {
            $this->info('Vincolo applicato: nessuna tessera doppia rimasta.');

            return self::SUCCESS;
        }

        $doppioni = $vincolo->doppioni();
        $this->warn("Vincolo non ancora applicato: {$doppioni->count()} soci hanno più tessere non annullate nello stesso anno. Sistemale dalla pagina Tessere.");
        foreach ($doppioni as $riga) {
            $this->line("  {$riga->nominativo} ({$riga->anno}): tessere {$riga->numeri}");
        }

        return self::SUCCESS;
    }
}
