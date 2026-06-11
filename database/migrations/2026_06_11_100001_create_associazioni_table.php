<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('associazioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('nome');
            $table->string('partita_iva', 20)->nullable()->unique();
            $table->string('codice_fiscale', 16)->nullable()->unique();
            $table->text('indirizzo')->nullable();
            $table->string('citta', 100)->nullable();
            $table->string('provincia', 2)->nullable();
            $table->string('cap', 5)->nullable();
            $table->string('email')->unique();
            $table->string('telefono', 20)->nullable();
            $table->decimal('costo_tessera', 10, 2)->nullable();
            $table->text('logo_url')->nullable();
            $table->boolean('attiva')->default(true);
            // Valori ammessi: 'solare' | 'annuale' (ex ENUM MySQL)
            $table->string('tipo_scadenza_default', 20)->default('solare');
            $table->integer('giorni_notifica_scadenza')->default(30);
            $table->text('template_email_scadenza')->nullable();
            $table->longText('privacy_policy_html')->nullable();
            // Presente solo in schema.sql (assente dal dump): config campi obbligatori per tenant
            $table->json('campi_obbligatori_config')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('associazioni');
    }
};
