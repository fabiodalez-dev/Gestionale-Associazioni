<?php

namespace App\Console\Commands;

use App\Models\PrestitoAttrezzatura;
use Illuminate\Console\Command;

/**
 * Promemoria ritardo prestiti attrezzature (f.10 piano "Organizzazione 3"):
 * seleziona i prestiti ancora attivi la cui restituzione prevista è già
 * passata (scope inRitardo) e li raggruppa per socio per il sollecito.
 *
 * L'invio reale della notifica (al socio e/o agli admin) viene collegato
 * nella fase Rifiniture; qui la selezione è completa e idempotente.
 *
 * NB: comando NON registrato nello scheduler (routes/console.php lo farà
 * la fase Rifiniture), come previsto dalle Regole operative del piano.
 */
class PrestitiPromemoria extends Command
{
    protected $signature = 'prestiti:promemoria';

    protected $description = 'Elenca i prestiti di attrezzature in ritardo (restituzione prevista passata, non restituiti)';

    public function handle(): int
    {
        // allTenants(): il comando gira fuori da una sessione tenant
        $inRitardo = PrestitoAttrezzatura::allTenants()
            ->inRitardo()
            ->with(['attrezzatura', 'socio'])
            ->orderBy('restituzione_prevista')
            ->get()
            ->filter(fn (PrestitoAttrezzatura $p) => $p->socio !== null);

        $perSocio = $inRitardo->groupBy('socio_id');

        foreach ($perSocio as $prestitiSocio) {
            /** @var PrestitoAttrezzatura $primo */
            $primo = $prestitiSocio->first();
            $this->line(sprintf(
                '- %s: %d prestiti in ritardo',
                $primo->socio->nome_completo,
                $prestitiSocio->count(),
            ));
        }

        $this->info("Prestiti in ritardo: {$inRitardo->count()} (soci coinvolti: {$perSocio->count()})");

        return self::SUCCESS;
    }
}
