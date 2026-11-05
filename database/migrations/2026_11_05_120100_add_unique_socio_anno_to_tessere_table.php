<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Tessere (issue #21): al massimo una tessera NON annullata per socio e anno
 * di validità.
 *
 * Perché una colonna derivata. Una tessera persa si annulla e se ne emette
 * un'altra dello stesso anno (flusso documentato), quindi il vincolo deve
 * escludere le tessere annullate. MariaDB non ha indici parziali e una
 * colonna generata non va bene (un INSERT che la valorizza, come il backup
 * SQL dell'aggiornamento, fallisce in strict mode): si usa una colonna
 * normale anno_non_annullata = anno_validita, NULL per le annullate, tenuta
 * allineata dal modello Tessera. I NULL non collidono nell'indice unique, su
 * SQLite come su MariaDB.
 *
 * Tessere 'Scaduta'. Esce dal vincolo solo lo stato 'Annullata': una tessera
 * con stato 'Scaduta' memorizzato (valore legacy, il backoffice non lo
 * accetta più) è comunque una tessera emessa per quell'anno e conta. Per
 * emetterne un'altra dello stesso anno va prima annullata (il messaggio di
 * errore lo indica). Le tessere annuali scadono dopo il loro anno, quindi il
 * caso è raro.
 *
 * Doppioni già presenti. Le tessere non hanno soft delete: due tessere non
 * annullate dello stesso socio e anno sono dati reali (numero stampato, QR
 * consegnato) e la migration NON le cancella né le modifica. La colonna viene
 * comunque aggiunta e valorizzata; se ci sono doppioni il vincolo NON viene
 * aggiunto e la migration lo segnala nel log con l'elenco delle coppie
 * (socio, anno) da sistemare. L'applicazione resta corretta anche senza
 * vincolo, perché ogni emissione ricontrolla la tessera dell'anno sotto lock
 * della riga del socio (TesseraIssuer). Sistemati i doppioni dal backoffice
 * (annullando, eliminando o cambiando anno alle tessere in più), il vincolo
 * si aggiunge rieseguendo questa migration:
 *   php artisan migrate:refresh --path=database/migrations/2026_11_05_120100_add_unique_socio_anno_to_tessere_table.php
 */
return new class extends Migration
{
    private const INDICE = 'uq_tessere_socio_anno';

    public function up(): void
    {
        if (! Schema::hasColumn('tessere', 'anno_non_annullata')) {
            Schema::table('tessere', function (Blueprint $table) {
                $table->integer('anno_non_annullata')->nullable()->after('anno_validita');
            });
        }

        // Valori per le tessere esistenti (stesso calcolo del modello Tessera)
        DB::table('tessere')->where('stato', '<>', 'Annullata')->update(['anno_non_annullata' => DB::raw('anno_validita')]);
        DB::table('tessere')->where('stato', 'Annullata')->update(['anno_non_annullata' => null]);

        if (Schema::hasIndex('tessere', self::INDICE)) {
            return;
        }

        $doppioni = DB::table('tessere')
            ->select('socio_id', 'anno_non_annullata', DB::raw('COUNT(*) as totale'))
            ->whereNotNull('anno_non_annullata')
            ->groupBy('socio_id', 'anno_non_annullata')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('socio_id')
            ->orderBy('anno_non_annullata')
            ->get();

        if ($doppioni->isNotEmpty()) {
            $messaggio = 'Vincolo unique tessere(socio_id, anno_non_annullata) NON aggiunto: '
                .$doppioni->count().' coppie socio/anno hanno più tessere non annullate. Nessuna tessera è stata modificata: '
                .'sistemare i doppioni e rieseguire la migration 2026_11_05_120100_add_unique_socio_anno_to_tessere_table.';

            Log::warning($messaggio, [
                'doppioni' => $doppioni->map(fn ($r) => [
                    'socio_id' => $r->socio_id,
                    'anno_validita' => (int) $r->anno_non_annullata,
                    'tessere' => (int) $r->totale,
                ])->all(),
            ]);

            // Visibile anche a chi lancia "php artisan migrate" da terminale
            if (app()->runningInConsole() && ! app()->runningUnitTests() && defined('STDERR')) {
                fwrite(STDERR, 'ATTENZIONE: '.$messaggio.PHP_EOL);
            }

            return;
        }

        Schema::table('tessere', function (Blueprint $table) {
            $table->unique(['socio_id', 'anno_non_annullata'], self::INDICE);
        });
    }

    public function down(): void
    {
        // Il vincolo può mancare se up() ha trovato doppioni
        if (Schema::hasIndex('tessere', self::INDICE)) {
            Schema::table('tessere', function (Blueprint $table) {
                $table->dropUnique(self::INDICE);
            });
        }

        if (Schema::hasColumn('tessere', 'anno_non_annullata')) {
            Schema::table('tessere', function (Blueprint $table) {
                $table->dropColumn('anno_non_annullata');
            });
        }
    }
};
