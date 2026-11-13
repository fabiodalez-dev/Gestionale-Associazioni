<?php

namespace App\Console\Commands;

use App\Models\Tessera;
use App\Services\Tessere\TesseraQrService;
use Illuminate\Console\Command;

/**
 * Chiude la finestra di compatibilità dei QR legacy: riscrive qr_code_url
 * con l'URL di verifica firmato per tutte le tessere che ancora lo hanno
 * senza firma (importate dal gestionale precedente). Da lanciare DOPO aver
 * ristampato quelle tessere: i QR già stampati senza firma smettono di
 * essere verificabili con il solo UUID.
 */
class TessereFirmaQr extends Command
{
    protected $signature = 'tessere:firma-qr {--dry-run : Elenca soltanto, senza scrivere}';

    protected $description = 'Riscrive con l\'URL firmato il qr_code_url delle tessere importate senza firma';

    public function handle(TesseraQrService $qr): int
    {
        $query = Tessera::allTenants()
            ->whereNotNull('qr_code_url')
            ->where('qr_code_url', '!=', '')
            ->where('qr_code_url', 'not like', '%signature=%');

        $totale = (clone $query)->count();
        if ($totale === 0) {
            $this->info('Nessuna tessera con QR legacy senza firma.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line("{$totale} tessere hanno un qr_code_url senza firma (dry-run, nessuna modifica).");

            return self::SUCCESS;
        }

        $aggiornate = 0;
        $query->chunkById(500, function ($tessere) use ($qr, &$aggiornate): void {
            foreach ($tessere as $tessera) {
                $tessera->forceFill(['qr_code_url' => $qr->verificationUrl($tessera)])->saveQuietly();
                $aggiornate++;
            }
        });

        $this->info("{$aggiornate} tessere aggiornate: da ora la verifica richiede l'URL firmato anche per loro.");

        return self::SUCCESS;
    }
}
