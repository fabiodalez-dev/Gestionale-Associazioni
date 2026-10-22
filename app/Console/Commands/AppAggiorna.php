<?php

namespace App\Console\Commands;

use App\Services\Update\UpdateService;
use Illuminate\Console\Command;

/**
 * Aggiornamento da terminale, stesso flusso della pagina /aggiornamenti
 * (come scripts/manual-upgrade.php di Pinakes in modalità CLI): utile dove
 * l'utente del web server non può scrivere i file dell'app, perché qui il
 * comando gira come proprietario dei file.
 */
class AppAggiorna extends Command
{
    protected $signature = 'app:aggiorna
        {--versione= : Versione da installare dalla release GitHub (predefinita: l\'ultima disponibile)}
        {--pacchetto= : Percorso di un pacchetto zip già scaricato (salta il download)}
        {--force : Non chiedere conferma}';

    protected $description = 'Aggiorna l\'applicazione all\'ultima release GitHub o da un pacchetto zip';

    public function handle(UpdateService $updater): int
    {
        $pacchetto = $this->option('pacchetto') ? (string) $this->option('pacchetto') : null;
        $versione = (string) ($this->option('versione') ?: '');

        if ($pacchetto !== null && ! is_file($pacchetto)) {
            $this->error("Pacchetto non trovato: {$pacchetto}");

            return self::FAILURE;
        }

        $this->line('Versione installata: <info>'.$updater->versioneCorrente().'</info>');

        if ($pacchetto === null && $versione === '') {
            $stato = $updater->controllaAggiornamenti();
            if ($stato['errore'] !== null) {
                $this->error($stato['errore']);

                return self::FAILURE;
            }
            if (! $stato['disponibile']) {
                $this->info('Sei già alla versione più recente ('.$stato['corrente'].').');

                return self::SUCCESS;
            }
            if (! $stato['pacchetto']) {
                $this->error('La release '.$stato['tag'].' non contiene il pacchetto installabile.');

                return self::FAILURE;
            }
            $versione = (string) $stato['ultima'];
        }

        $req = $updater->requisiti();
        foreach ($req['righe'] as $r) {
            $this->line(sprintf(' %s %s: %s', $r['ok'] ? '<info>✓</info>' : '<error>✗</error>', $r['nome'], $r['attuale']));
        }
        if (! $req['ok']) {
            $this->error('Requisiti non soddisfatti.');

            return self::FAILURE;
        }

        $destinazione = $pacchetto !== null ? 'dal pacchetto '.$pacchetto : 'alla versione '.$versione;
        if (! $this->option('force') && ! $this->confirm("Aggiornare {$destinazione}? Backup del database e dei file, manutenzione durante la copia.")) {
            $this->warn('Annullato.');

            return self::FAILURE;
        }

        $this->info('Aggiornamento in corso…');
        $esito = $updater->applicaAggiornamento($versione !== '' ? $versione : '0.0.0', null, $pacchetto);

        if ($esito['success']) {
            $this->info('Aggiornamento completato: ora sei alla versione '.$esito['versione'].'.');

            return self::SUCCESS;
        }

        $this->error('Aggiornamento non riuscito: '.$esito['error']);
        $this->line('Dettagli nello storico della pagina Aggiornamenti e in storage/logs/laravel.log.');

        return self::FAILURE;
    }
}
