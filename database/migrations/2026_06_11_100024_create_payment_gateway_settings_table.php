<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabella presente solo in schema.sql (assente dal dump reale) — vedi schema-dati §2.29.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->unique('uniq_pg_assoc');
            $table->boolean('stripe_enabled')->default(false);
            $table->string('stripe_publishable_key')->nullable()->default('');
            $table->text('stripe_secret_key_encrypted')->nullable();
            $table->text('stripe_webhook_secret_encrypted')->nullable();
            $table->boolean('paypal_enabled')->default(false);
            $table->string('paypal_client_id')->nullable()->default('');
            $table->text('paypal_client_secret_encrypted')->nullable();
            // Valori ammessi: 'sandbox' | 'live' (ex ENUM MySQL)
            $table->string('paypal_mode', 10)->default('sandbox');
            $table->string('paypal_webhook_id')->nullable()->default('');
            $table->boolean('auto_attivazione_pagamento')->default(true);
            $table->string('valuta', 3)->default('EUR');
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_settings');
    }
};
