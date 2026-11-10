<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storico delle importazioni (#55): chi, quando, quale file, quante righe
 * create, aggiornate e scartate, con gli errori riga per riga per il report
 * CSV. Le voci più vecchie di 12 mesi vengono eliminate da model:prune.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('importazioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->uuid('utente_id')->nullable();
            $table->string('tipo', 30);
            $table->string('nome_file', 255);
            $table->unsignedInteger('righe_lette')->default(0);
            $table->unsignedInteger('create')->default(0);
            $table->unsignedInteger('aggiornate')->default(0);
            $table->unsignedInteger('scartate')->default(0);
            $table->json('errori')->nullable();
            $table->unsignedInteger('durata_ms')->default(0);
            $table->timestamps();

            $table->index(['associazione_id', 'created_at']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('utente_id')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('importazioni');
    }
};
