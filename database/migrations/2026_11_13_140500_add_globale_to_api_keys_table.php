<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Chiavi API globali con flag esplicito (hardening audit run-2).
 *
 * Prima una chiave era "globale" (cross-tenant, solo super admin) quando
 * associazione_id era NULL; la FK nullOnDelete trasformava così le chiavi di
 * un'associazione eliminata in chiavi globali. Da ora:
 * - colonna `globale` (default false): solo le chiavi con il flag e senza
 *   associazione sono globali, le altre senza associazione vengono rifiutate;
 * - compatibilità: le chiavi esistenti con associazione_id NULL diventano
 *   globale=true, perché oggi funzionano come globali e devono continuare a
 *   farlo (quelle nate da associazioni eliminate non sono distinguibili: il
 *   super admin le ricontrolla nella pagina Chiavi API, vedi AGGIORNAMENTI);
 * - la FK diventa cascadeOnDelete: eliminando l'associazione spariscono anche
 *   le sue chiavi.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('api_keys', 'globale')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->boolean('globale')->default(false)->after('associazione_id');
            });
        }

        DB::table('api_keys')->whereNull('associazione_id')->update(['globale' => true]);

        $this->sostituisciForeignKey('cascade');
    }

    public function down(): void
    {
        $this->sostituisciForeignKey('set null');

        if (Schema::hasColumn('api_keys', 'globale')) {
            Schema::table('api_keys', function (Blueprint $table) {
                $table->dropColumn('globale');
            });
        }
    }

    /**
     * Ricrea la FK api_keys.associazione_id con la regola di delete richiesta.
     * Il nome viene letto dallo schema (installazioni da schema.sql o da
     * migration hanno lo stesso nome, ma non si dà per scontato); se la FK
     * manca viene solo creata.
     */
    private function sostituisciForeignKey(string $onDelete): void
    {
        $esistente = collect(Schema::getForeignKeys('api_keys'))
            ->first(fn (array $fk) => $fk['columns'] === ['associazione_id']);

        Schema::table('api_keys', function (Blueprint $table) use ($esistente, $onDelete) {
            if ($esistente !== null) {
                // SQLite rimuove le FK solo per colonne (ricostruendo la
                // tabella), MySQL/MariaDB per nome
                $table->dropForeign(
                    Schema::getConnection()->getDriverName() === 'sqlite' || empty($esistente['name'])
                        ? ['associazione_id']
                        : $esistente['name']
                );
            }

            $table->foreign('associazione_id')
                ->references('id')
                ->on('associazioni')
                ->onDelete($onDelete);
        });
    }
};
