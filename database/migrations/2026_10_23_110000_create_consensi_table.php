<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro dei consensi (GDPR art. 7: dimostrare il consenso): per ogni
 * modulo inviato, testo esatto mostrato, risposta, data, origine e IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consensi', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id')->nullable()->index();
            $table->string('chiave', 100);
            $table->text('testo');
            $table->boolean('accettato');
            $table->string('origine', 50);
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consensi');
    }
};
