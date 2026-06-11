<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Richieste di iscrizione socio dal form pubblico /a/{slug}/iscriviti
 * (PIANO-ISCRIZIONI §Schema dati).
 *
 * Stati ammessi: in_attesa | approvata | rifiutata.
 * - account_id cascadeOnDelete: la richiesta segue il ciclo di vita dell'account;
 * - socio_id nullOnDelete: il preiscritto creato può essere eliminato al rifiuto;
 * - dati_form: snapshot JSON di TUTTI i valori inviati dal form;
 * - una sola richiesta 'in_attesa' per account (check applicativo nel service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('richieste_iscrizione', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->uuid('account_id');
            $table->uuid('socio_id')->nullable();
            $table->string('stato', 20)->default('in_attesa');
            $table->json('dati_form');
            $table->string('motivo_rifiuto')->nullable();
            $table->uuid('gestita_da')->nullable();
            $table->dateTime('gestita_at')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'stato']);
            $table->index(['account_id', 'stato']);

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->nullOnDelete();
            $table->foreign('gestita_da')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('richieste_iscrizione');
    }
};
