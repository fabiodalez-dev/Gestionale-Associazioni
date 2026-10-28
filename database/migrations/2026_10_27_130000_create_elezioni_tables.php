<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elezioni e rinnovo delle cariche (issue #9): un'elezione si tiene in
 * un'assemblea per un organo (direttivo, revisori, probiviri). Candidature
 * dall'area soci o dalla segreteria, scheda segreta con preferenze (online
 * in schede_elezione, anonime e senza date; chi ha votato in
 * votanti_elezione), preferenze dalle schede cartacee sommate allo
 * scrutinio, proclamazione che chiude le cariche uscenti e crea le nuove.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('elezioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('riunione_id');
            // Valori ammessi: vedi Elezione::ORGANI
            $table->string('organo', 30);
            $table->unsignedTinyInteger('posti');
            $table->unsignedTinyInteger('max_preferenze');
            $table->unsignedSmallInteger('durata_mandato_mesi')->default(36);
            $table->date('candidature_dal');
            $table->date('candidature_al');
            // Anzianità di iscrizione minima per candidarsi (0 = nessuna)
            $table->unsignedSmallInteger('anzianita_minima_mesi')->default(0);
            // Valori ammessi: vedi Elezione::REGOLE_PARITA
            $table->string('regola_parita', 30)->default('anzianita_iscrizione');
            // Valori ammessi: vedi Elezione::STATI
            $table->string('stato', 20)->default('candidature');
            $table->dateTime('voto_aperto_at')->nullable();
            $table->dateTime('voto_chiuso_at')->nullable();
            // Schede cartacee scrutinate: valide (preferenze in candidature.preferenze_sala), bianche, nulle
            $table->unsignedSmallInteger('schede_valide_sala')->default(0);
            $table->unsignedSmallInteger('schede_bianche_sala')->default(0);
            $table->unsignedSmallInteger('schede_nulle_sala')->default(0);
            $table->dateTime('proclamata_at')->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('riunione_id')->references('id')->on('riunioni')->cascadeOnDelete();
        });

        Schema::create('candidature', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('elezione_id');
            $table->uuid('socio_id');
            $table->text('presentazione')->nullable();
            // Valori ammessi: 'area_soci'|'segreteria'
            $table->string('origine', 20);
            // La candidatura inserita dalla segreteria vale dopo l'accettazione
            $table->dateTime('accettata_at')->nullable();
            $table->dateTime('ritirata_at')->nullable();
            // Preferenze dalle schede cartacee, inserite allo scrutinio
            $table->unsignedSmallInteger('preferenze_sala')->default(0);
            // Esito dello scrutinio
            $table->unsignedSmallInteger('preferenze_totali')->nullable();
            $table->unsignedSmallInteger('posizione')->nullable();
            $table->boolean('eletta')->default(false);
            $table->timestamps();

            $table->unique(['elezione_id', 'socio_id'], 'uq_candidature_elezione_socio');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('elezione_id')->references('id')->on('elezioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::create('schede_elezione', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('elezione_id');
            // Id delle candidature scelte; [] = scheda bianca
            $table->json('preferenze');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('elezione_id')->references('id')->on('elezioni')->cascadeOnDelete();
        });

        Schema::create('votanti_elezione', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('elezione_id');
            $table->uuid('votante_socio_id');
            $table->uuid('rappresentato_socio_id');
            $table->timestamps();

            $table->unique(['elezione_id', 'rappresentato_socio_id'], 'uq_votanti_elezione_rappresentato');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('elezione_id')->references('id')->on('elezioni')->cascadeOnDelete();
            $table->foreign('votante_socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('rappresentato_socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votanti_elezione');
        Schema::dropIfExists('schede_elezione');
        Schema::dropIfExists('candidature');
        Schema::dropIfExists('elezioni');
    }
};
