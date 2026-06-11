<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('socio_id')->index();
            $table->uuid('associazione_id')->index();
            $table->integer('anno');
            $table->decimal('importo', 10, 2);
            $table->date('data_scadenza');
            $table->date('data_pagamento')->nullable();
            // Valori ammessi: 'Pagata' | 'Da Pagare' | 'Scaduta' | 'In Scadenza' (ex ENUM MySQL; 'Scaduta'/'In Scadenza' derivati a runtime)
            $table->string('stato', 20)->default('Da Pagare');
            $table->string('tipo', 100);
            $table->text('note')->nullable();
            // Colonne pagamento online (solo schema.sql, assenti dal dump)
            $table->string('metodo_pagamento', 50)->nullable();
            // Valori ammessi: 'stripe' | 'paypal'
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->char('payment_token', 64)->nullable()->index();
            $table->dateTime('payment_token_expires')->nullable();
            $table->timestamps();

            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote');
    }
};
