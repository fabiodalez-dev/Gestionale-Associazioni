<?php

namespace Tests\Feature\Admin;

use Illuminate\Support\Facades\File;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Backup\BackupJobFactory;
use Tests\TestCase;
use ZipArchive;

/**
 * Il .env del wizard (come .env.example) contiene BACKUP_ARCHIVE_PASSWORD=
 * vuoto: prima config/backup.php la passava a spatie come password vuota e
 * ogni backup, notturno e dell'aggiornamento, falliva alla chiusura dello zip
 * (ZipArchive::close(): Invalid argument). Il backup qui è di una cartella di
 * file: lo zip è lo stesso del backup del database, senza dipendere da
 * mysqldump e dalla versione del server.
 */
class BackupPasswordVuotaTest extends TestCase
{
    public function test_password_vuota_dei_backup_vuol_dire_archivi_non_cifrati_e_il_backup_riesce(): void
    {
        $precedente = getenv('BACKUP_ARCHIVE_PASSWORD');
        putenv('BACKUP_ARCHIVE_PASSWORD=');
        $_ENV['BACKUP_ARCHIVE_PASSWORD'] = $_SERVER['BACKUP_ARCHIVE_PASSWORD'] = '';
        try {
            $password = (require config_path('backup.php'))['backup']['password'];
        } finally {
            $precedente === false ? putenv('BACKUP_ARCHIVE_PASSWORD') : putenv('BACKUP_ARCHIVE_PASSWORD='.$precedente);
            unset($_ENV['BACKUP_ARCHIVE_PASSWORD'], $_SERVER['BACKUP_ARCHIVE_PASSWORD']);
        }
        $this->assertNull($password);

        $nome = 'backup-test-'.uniqid();
        $sorgente = storage_path('framework/testing/'.$nome);
        $destinazione = storage_path('app/private/'.$nome);
        File::ensureDirectoryExists($sorgente);
        File::put($sorgente.'/documento.txt', 'contenuto del backup');
        config([
            'backup.backup.name' => $nome,
            'backup.backup.password' => $password,
            'backup.backup.source.files.include' => [$sorgente],
            'backup.backup.source.files.exclude' => [],
            'backup.backup.source.databases' => [],
            'backup.backup.destination.disks' => ['local'],
        ]);
        try {
            // Stesso job di backup:run, con la configurazione di questo test
            // (il comando ha già letto quella dell'avvio)
            BackupJobFactory::createFromConfig(Config::fromArray(config('backup')))
                ->disableSignals()
                ->run();

            $zip = File::files($destinazione);
            $this->assertCount(1, $zip);
            $archivio = new ZipArchive;
            $this->assertTrue($archivio->open($zip[0]->getPathname()));
            // Non cifrato: il contenuto si legge senza password
            $contenuti = [];
            for ($i = 0; $i < $archivio->numFiles; $i++) {
                $contenuti[] = $archivio->getFromIndex($i);
            }
            $archivio->close();
            $this->assertContains('contenuto del backup', $contenuti);
        } finally {
            File::deleteDirectory($sorgente);
            File::deleteDirectory($destinazione);
        }
    }
}
