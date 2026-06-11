<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prenotazioni eventi del frontend pubblico.
 *
 * Stati ammessi (macchina a stati, vedi App\Models\Prenotazione):
 * in_attesa_pagamento | confermata | lista_attesa | annullata | scaduta.
 * Il check-in NON è uno stato: è checkin_at valorizzato su una confermata.
 * account_id è restrictOnDelete: la cancellazione GDPR di un account
 * è un'anonimizzazione, le prenotazioni (contabilità) restano intatte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prenotazioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('evento_id');
            $table->uuid('account_id');
            $table->string('codice', 20); // es. PRN-7K3M2A (base32 non ambiguo), unique per tenant
            $table->char('qr_token', 64)->unique(); // unique globale (verifica biglietto senza tenant)
            $table->string('stato', 20);
            $table->unsignedTinyInteger('posti')->default(1);
            // Snapshot del prezzo al momento della prenotazione
            $table->decimal('prezzo_unitario', 10, 2)->default(0);
            $table->decimal('importo_totale', 10, 2)->default(0);
            // Valori ammessi: 'stripe' | 'paypal'
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->dateTime('pagata_at')->nullable();
            $table->dateTime('scadenza_pagamento_at')->nullable();
            $table->dateTime('promossa_at')->nullable();
            $table->dateTime('annullata_at')->nullable();
            $table->text('motivo_annullamento')->nullable();
            $table->dateTime('checkin_at')->nullable();
            $table->uuid('checkin_utente_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['associazione_id', 'codice']);
            $table->index(['evento_id', 'stato']);
            $table->index(['account_id', 'created_at']);
            $table->index(['stato', 'scadenza_pagamento_at']);

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('evento_id')->references('id')->on('eventi')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('accounts')->restrictOnDelete();
            $table->foreign('checkin_utente_id')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prenotazioni');
    }
};
