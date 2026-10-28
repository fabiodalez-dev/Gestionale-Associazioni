<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Proposte dei soci (issue #10): il socio presenta una proposta per il
 * direttivo o per l'assemblea, la segreteria la pubblica, gli altri soci
 * aderiscono e al raggiungimento della soglia la proposta va esaminata e
 * può diventare un punto dell'ordine del giorno di una riunione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposte', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            // Proponente
            $table->uuid('socio_id');
            $table->string('titolo', 200);
            $table->text('testo');
            // Valori ammessi: vedi Proposta::CATEGORIE
            $table->string('categoria', 30);
            // Valori ammessi: vedi Proposta::DESTINATARI ('direttivo'|'assemblea')
            $table->string('destinatario', 20);
            // Valori ammessi: vedi Proposta::STATI
            $table->string('stato', 20)->default('in_revisione');
            // Motivazione della non ammissione o dell'esito
            $table->text('motivazione')->nullable();
            // Riunione e punto dell'OdG in cui è stata inserita
            $table->uuid('riunione_id')->nullable();
            $table->unsignedSmallInteger('punto_indice')->nullable();
            $table->dateTime('pubblicata_at')->nullable();
            $table->dateTime('soglia_raggiunta_at')->nullable();
            $table->dateTime('esito_at')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'stato'], 'idx_proposte_assoc_stato');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('riunione_id')->references('id')->on('riunioni')->nullOnDelete();
        });

        Schema::create('adesioni_proposta', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('proposta_id');
            $table->uuid('socio_id');
            $table->timestamps();

            $table->unique(['proposta_id', 'socio_id'], 'uq_adesioni_proposta_socio');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('proposta_id')->references('id')->on('proposte')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('adesioni_proposta');
        Schema::dropIfExists('proposte');
    }
};
