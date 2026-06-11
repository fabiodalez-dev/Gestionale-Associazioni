<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tessera_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->uuid('tipo_socio_id')->nullable()->index();
            $table->string('titolo')->nullable()->default('Template Tessera');
            $table->text('contenuto');
            $table->boolean('attivo')->default(true);
            $table->timestamps();

            // Un solo template per coppia associazione+tipo (NULL = template generico, unicità applicativa)
            $table->unique(['associazione_id', 'tipo_socio_id']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('tipo_socio_id')->references('id')->on('tipi_socio')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tessera_templates');
    }
};
