<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le richieste di iscrizione possono arrivare anche da OSPITI senza
 * account (form pubblico aperto a tutti): account_id diventa nullable
 * e l'anagrafica dell'ospite vive nello snapshot dati_form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('richieste_iscrizione', function (Blueprint $table): void {
            $table->uuid('account_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('richieste_iscrizione', function (Blueprint $table): void {
            $table->uuid('account_id')->nullable(false)->change();
        });
    }
};
