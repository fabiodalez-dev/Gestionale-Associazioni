<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campi_personalizzati', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->string('nome_campo', 100);
            // Valori ammessi: 'text' | 'email' | 'tel' | 'number' | 'date' | 'url' | 'textarea' | 'select' | 'checkbox' (ex ENUM MySQL)
            $table->string('tipo_campo', 20);
            $table->text('descrizione')->nullable();
            $table->text('opzioni')->nullable();
            $table->boolean('obbligatorio')->default(false);
            // Presente solo in schema.sql (assente dal dump): campo richiesto nel form pubblico di preiscrizione
            $table->boolean('obbligatorio_preiscrizione')->default(false);
            $table->integer('ordine')->default(0);
            $table->timestamps();

            $table->unique(['associazione_id', 'nome_campo']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campi_personalizzati');
    }
};
