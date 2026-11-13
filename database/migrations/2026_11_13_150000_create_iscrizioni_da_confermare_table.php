<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Iscrizioni inviate da ospiti (senza account) dal modulo /a/{slug}/iscriviti
 * in attesa che il richiedente confermi l'email con il link ricevuto.
 *
 * Finché non è confermata la richiesta non crea soci né RichiestaIscrizione,
 * non arriva alla segreteria e non occupa l'email; scade dopo 48 ore.
 * I dati del modulo sono cifrati con la APP_KEY (cast encrypted:array).
 * Tabella nuova: richieste e soci "In Attesa" già esistenti non cambiano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('iscrizioni_da_confermare', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->string('email');
            $table->mediumText('dati_form');
            $table->string('ip', 45)->nullable();
            $table->string('referral_codice', 50)->nullable();
            $table->dateTime('scade_at');
            $table->timestamps();

            $table->index(['associazione_id', 'email']);
            $table->index('scade_at');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('iscrizioni_da_confermare');
    }
};
