<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contatore per la numerazione unificata delle tessere ({anno}{progressivo 4 cifre})
 * per coppia (associazione, anno). Sostituisce i COUNT/MAX runtime del legacy
 * (concorrenza-safe con lockForUpdate in transazione).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tessera_counters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->integer('anno');
            $table->unsignedInteger('ultimo_numero')->default(0);
            $table->timestamps();

            $table->unique(['associazione_id', 'anno']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tessera_counters');
    }
};
