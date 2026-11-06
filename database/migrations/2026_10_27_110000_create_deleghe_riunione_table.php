<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Deleghe per l'assemblea dei soci (issue #7): il socio che non partecipa
 * delega un altro avente diritto. Una sola delega per delegante e riunione
 * (la revoca cancella la riga, lo storico resta nel log attività); il numero
 * massimo di deleghe per delegato e l'eventuale divieto per chi ricopre una
 * carica sono impostazioni della riunione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riunioni', function (Blueprint $table) {
            // 0 = deleghe non ammesse
            $table->unsignedTinyInteger('max_deleghe_per_socio')->default(1)->after('escludi_minorenni_voto');
            $table->boolean('deleghe_vietate_cariche')->default(false)->after('max_deleghe_per_socio');
        });

        Schema::create('deleghe_riunione', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('riunione_id');
            $table->uuid('delegante_socio_id');
            $table->uuid('delegato_socio_id');
            // Valori ammessi: DelegaRiunione::ORIGINE_* ('area_soci'|'segreteria')
            $table->string('origine', 20);
            // Utente della segreteria che ha registrato la delega cartacea
            $table->uuid('registrata_da')->nullable();
            $table->timestamps();

            $table->unique(['riunione_id', 'delegante_socio_id'], 'uq_deleghe_riunione_delegante');
            $table->index(['riunione_id', 'delegato_socio_id'], 'idx_deleghe_riunione_delegato');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('riunione_id')->references('id')->on('riunioni')->cascadeOnDelete();
            $table->foreign('delegante_socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('delegato_socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('registrata_da')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deleghe_riunione');

        Schema::table('riunioni', function (Blueprint $table) {
            $table->dropColumn(['max_deleghe_per_socio', 'deleghe_vietate_cariche']);
        });
    }
};
