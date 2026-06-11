<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tessere', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('socio_id')->index();
            $table->uuid('associazione_id');
            $table->string('numero_tessera', 50);
            $table->integer('anno_validita');
            $table->date('data_emissione');
            $table->date('data_scadenza')->index();
            // Valori ammessi: 'solare' | 'annuale' (ex ENUM MySQL)
            $table->string('tipo_scadenza', 20);
            // Valori ammessi: 'Attiva' | 'Scaduta' | 'Sospesa' | 'Annullata' (ex ENUM MySQL)
            $table->string('stato', 20)->default('Attiva');
            $table->text('qr_code_url')->nullable();
            $table->string('template_tessera', 100)->nullable()->index();
            // Presente solo in schema.sql (assente dal dump): tessera emessa contestualmente a un evento (senza FK)
            $table->uuid('evento_creazione_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['associazione_id', 'numero_tessera']);
            $table->index(['associazione_id', 'anno_validita']);
            $table->index(['socio_id', 'associazione_id', 'data_emissione']);

            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tessere');
    }
};
