<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gruppi_dinamici', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->string('nome_gruppo', 100);
            $table->text('descrizione')->nullable();
            $table->json('filtri_json');
            $table->timestamps();

            $table->unique(['associazione_id', 'nome_gruppo']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gruppi_dinamici');
    }
};
