<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richieste di iscrizione alla piattaforma inviate dalle associazioni
 * (registrazione autonoma con revisione o automatica).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('richieste_registrazione', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('stato', 20)->default('in_attesa')->index();

            // Dati dell'associazione
            $table->string('nome');
            $table->string('email');
            $table->string('tipo', 20)->nullable();
            $table->string('codice_fiscale', 16)->nullable();
            $table->string('partita_iva', 20)->nullable();
            $table->string('telefono', 20)->nullable();
            $table->string('citta', 100)->nullable();
            $table->string('provincia', 2)->nullable();
            $table->unsignedInteger('numero_soci_stimato')->nullable();
            $table->text('messaggio')->nullable();

            // Primo amministratore
            $table->string('admin_nome', 100);
            $table->string('admin_cognome', 100)->nullable();
            $table->string('admin_email');
            $table->string('admin_password_hash');

            $table->string('ip', 45)->nullable();
            $table->timestamp('privacy_accettata_at')->nullable();
            $table->string('motivo_rifiuto', 1000)->nullable();
            $table->uuid('associazione_id')->nullable()->index();
            $table->uuid('gestita_da')->nullable();
            $table->timestamp('gestita_at')->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->nullOnDelete();
            $table->foreign('gestita_da')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('richieste_registrazione');
    }
};
