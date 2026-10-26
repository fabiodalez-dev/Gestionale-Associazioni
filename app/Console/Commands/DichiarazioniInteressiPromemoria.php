<?php

namespace App\Console\Commands;

use App\Models\Associazione;
use App\Services\Struttura\ConflittiService;
use App\Settings\PartecipazioneSettings;
use Illuminate\Console\Command;

/**
 * Promemoria mensile delle dichiarazioni sui conflitti di interesse
 * (issue #11): per ogni associazione con una raccolta in corso avvisa i
 * titolari di cariche che non hanno ancora dichiarato. Idempotente nel mese
 * (promemoria_inviati, tipo dichiarazione_interessi, step AAAAMM).
 */
class DichiarazioniInteressiPromemoria extends Command
{
    protected $signature = 'conflitti:promemoria-dichiarazioni';

    protected $description = 'Ricorda la dichiarazione sui conflitti di interesse ai titolari di cariche che non l\'hanno presentata';

    public function handle(ConflittiService $conflitti): int
    {
        $inviati = 0;

        Associazione::query()
            ->where('attiva', true)
            ->get()
            ->each(function (Associazione $associazione) use ($conflitti, &$inviati) {
                $anno = $associazione->impostazioni(PartecipazioneSettings::class)->dichiarazioni_anno_campagna;

                if ($anno !== null) {
                    $inviati += $conflitti->invia($associazione, $anno);
                }
            });

        $this->info("Promemoria dichiarazioni inviati: {$inviati}");

        return self::SUCCESS;
    }
}
