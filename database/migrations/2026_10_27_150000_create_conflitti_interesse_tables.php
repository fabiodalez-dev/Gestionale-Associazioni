<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conflitti di interesse (issue #11):
 * - dichiarazioni_interessi: dichiarazione annuale di chi ricopre una carica
 *   (nessun conflitto oppure interessi da dichiarare), una per socio e anno;
 * - conflitti_riunione: conflitto dichiarato su un punto dell'OdG di una
 *   riunione; il socio non vota su quel punto ma resta presente ai fini del
 *   quorum costitutivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dichiarazioni_interessi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id');
            $table->unsignedSmallInteger('anno');
            $table->boolean('nessun_conflitto')->default(false);
            // Ruoli in altre organizzazioni, attività professionali, rapporti economici, parenti coinvolti
            $table->text('interessi')->nullable();
            $table->dateTime('dichiarata_at');
            // Utente della segreteria che l'ha registrata (null = dall'area soci)
            $table->uuid('registrata_da')->nullable();
            $table->timestamps();

            $table->unique(['socio_id', 'anno'], 'uq_dichiarazioni_interessi_socio_anno');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('registrata_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('conflitti_riunione', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('riunione_id');
            $table->unsignedSmallInteger('punto_indice');
            $table->uuid('socio_id');
            $table->text('motivazione')->nullable();
            $table->timestamps();

            $table->unique(['riunione_id', 'punto_indice', 'socio_id'], 'uq_conflitti_riunione_punto_socio');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('riunione_id')->references('id')->on('riunioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflitti_riunione');
        Schema::dropIfExists('dichiarazioni_interessi');
    }
};
