<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valori_campi_personalizzati', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('socio_id')->index();
            $table->uuid('campo_id')->index();
            $table->text('valore')->nullable();
            $table->timestamps();

            $table->unique(['socio_id', 'campo_id']);
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('campo_id')->references('id')->on('campi_personalizzati')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('valori_campi_personalizzati');
    }
};
