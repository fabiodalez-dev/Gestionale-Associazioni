<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rubrica interna dei soci con consenso (issue #14):
 * - rubrica_schede: la scheda che il socio decide di mostrare agli altri
 *   soci, con i campi scelti uno per uno; esiste solo dopo il consenso
 *   esplicito (registrato anche in `consensi`) e si spegne alla revoca;
 * - rubrica_contatti: messaggi inviati tramite la piattaforma senza
 *   rivelare l'email del destinatario, per il limite di frequenza e per le
 *   segnalazioni di uso improprio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubrica_schede', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id')->unique();
            $table->boolean('attiva')->default(false);
            // Campi mostrati: vedi RubricaScheda::CAMPI
            $table->json('campi_visibili')->nullable();
            $table->string('presentazione', 1000)->nullable();
            // Interessi e competenze: ["fotografia", "contabilità", ...]
            $table->json('interessi')->nullable();
            $table->dateTime('consenso_at')->nullable();
            $table->dateTime('revocata_at')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'attiva'], 'idx_rubrica_schede_attiva');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::create('rubrica_contatti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('mittente_socio_id');
            $table->uuid('destinatario_socio_id');
            $table->string('oggetto', 150);
            $table->dateTime('segnalato_at')->nullable();
            $table->timestamps();

            $table->index(['mittente_socio_id', 'created_at'], 'idx_rubrica_contatti_mittente');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('mittente_socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('destinatario_socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubrica_contatti');
        Schema::dropIfExists('rubrica_schede');
    }
};
