<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estensioni del modulo documenti-verbali-scadenze (spec §6):
 *
 * - tabella `verbale_firmatari`: firmatari strutturati dei verbali
 *   (socio, utente admin o nominativo libero, con ruolo e data firma);
 * - `scadenze`: tracciamento completamento (completata_da/completata_at)
 *   e promemoria reali (promemoria_inviato_at) — miglioramento 3 della spec;
 * - `documenti_socio`: soft delete per il cestino (miglioramento 11);
 * - indici compositi consigliati dalla spec.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verbale_firmatari', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('verbale_id')->index();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id')->nullable()->index();
            $table->uuid('utente_id')->nullable()->index();
            // Nominativo libero (in alternativa a socio/utente)
            $table->string('nome')->nullable();
            // Ruolo nella riunione: es. Presidente, Segretario, Consigliere
            $table->string('ruolo', 100)->nullable();
            $table->timestamp('firmato_at')->nullable();
            $table->timestamps();

            $table->foreign('verbale_id')->references('id')->on('verbali')->cascadeOnDelete();
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->nullOnDelete();
            $table->foreign('utente_id')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::table('scadenze', function (Blueprint $table) {
            $table->uuid('completata_da')->nullable()->after('promemoria_giorni');
            $table->timestamp('completata_at')->nullable()->after('completata_da');
            $table->timestamp('promemoria_inviato_at')->nullable()->after('completata_at');

            $table->foreign('completata_da')->references('id')->on('utenti')->nullOnDelete();
            $table->index(['associazione_id', 'stato', 'data_scadenza'], 'idx_scadenze_assoc_stato_data');
        });

        Schema::table('documenti_socio', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('verbali', function (Blueprint $table) {
            $table->index(['associazione_id', 'data_riunione'], 'idx_verbali_assoc_data');
        });

        Schema::table('documenti', function (Blueprint $table) {
            $table->index(['associazione_id', 'created_at'], 'idx_documenti_assoc_created');
        });
    }

    public function down(): void
    {
        Schema::table('documenti', function (Blueprint $table) {
            $table->dropIndex('idx_documenti_assoc_created');
        });

        Schema::table('verbali', function (Blueprint $table) {
            $table->dropIndex('idx_verbali_assoc_data');
        });

        Schema::table('documenti_socio', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('scadenze', function (Blueprint $table) {
            $table->dropIndex('idx_scadenze_assoc_stato_data');
            $table->dropForeign(['completata_da']);
            $table->dropColumn(['completata_da', 'completata_at', 'promemoria_inviato_at']);
        });

        Schema::dropIfExists('verbale_firmatari');
    }
};
