<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categorie_socio', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->string('nome');
            $table->text('descrizione')->nullable();
            // Usato come testo fallback per il corpo della tessera PDF (vedi specs/tessere.md)
            $table->text('testo_tessera')->nullable();
            $table->timestamps();

            $table->unique(['associazione_id', 'nome']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorie_socio');
    }
};
