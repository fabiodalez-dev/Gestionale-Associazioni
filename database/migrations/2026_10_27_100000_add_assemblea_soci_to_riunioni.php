<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assemblea dei soci (issue #6): le riunioni diventano di un organo
 * (direttivo, assemblea ordinaria o straordinaria). Per le assemblee:
 * - seconda convocazione e regole di quorum costitutivo per convocazione;
 * - requisiti per gli aventi diritto (quota dell'anno pagata, minorenni
 *   senza diritto di voto) e momento in cui l'elenco è stato calcolato;
 * - per partecipante: diritto di voto e ora di registrazione della presenza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riunioni', function (Blueprint $table) {
            // Valori ammessi: vedi Riunione::ORGANI
            $table->string('organo', 30)->default('direttivo')->after('titolo');
            $table->dateTime('seconda_convocazione_at')->nullable()->after('data_ora');
            // Valori ammessi: vedi Riunione::QUORUM (null = predefinito per organo)
            $table->string('quorum_prima', 30)->nullable()->after('seconda_convocazione_at');
            $table->string('quorum_seconda', 30)->nullable()->after('quorum_prima');
            $table->boolean('requisito_quota_pagata')->default(false)->after('quorum_seconda');
            $table->boolean('escludi_minorenni_voto')->default(true)->after('requisito_quota_pagata');
            // Snapshot dell'elenco aventi diritto: quando è stato calcolato
            $table->dateTime('aventi_diritto_at')->nullable()->after('escludi_minorenni_voto');
        });

        Schema::table('riunioni_partecipanti', function (Blueprint $table) {
            $table->boolean('avente_diritto_voto')->default(true)->after('carica_ruolo');
            $table->dateTime('presente_at')->nullable()->after('stato');

            $table->index(['riunione_id', 'stato'], 'idx_riunioni_partecipanti_stato');
        });
    }

    public function down(): void
    {
        Schema::table('riunioni_partecipanti', function (Blueprint $table) {
            $table->dropIndex('idx_riunioni_partecipanti_stato');
            $table->dropColumn(['avente_diritto_voto', 'presente_at']);
        });

        Schema::table('riunioni', function (Blueprint $table) {
            $table->dropColumn([
                'organo',
                'seconda_convocazione_at',
                'quorum_prima',
                'quorum_seconda',
                'requisito_quota_pagata',
                'escludi_minorenni_voto',
                'aventi_diritto_at',
            ]);
        });
    }
};
