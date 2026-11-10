<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Calendario chiusure (#34): giorni o periodi in cui l'associazione, o una
 * sola sede, è chiusa. Le festività nazionali non stanno qui: si calcolano
 * da regole (CalendarioAperture). ogni_anno = la chiusura si ripete negli
 * stessi giorni ogni anno; attiva = sospendibile senza cancellarla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chiusure', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->uuid('sede_id')->nullable();
            $table->string('titolo', 150);
            $table->date('data_inizio');
            $table->date('data_fine');
            $table->boolean('ogni_anno')->default(false);
            $table->boolean('attiva')->default(true);
            $table->timestamps();

            $table->index(['associazione_id', 'data_inizio']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('sede_id')->references('id')->on('sedi')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chiusure');
    }
};
