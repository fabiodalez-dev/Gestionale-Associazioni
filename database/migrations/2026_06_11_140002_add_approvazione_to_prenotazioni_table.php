<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo iscrizioni (PIANO-ISCRIZIONI §Schema dati): supporto al nuovo
 * stato 'in_approvazione' delle prenotazioni (i posti CONTANO come occupati,
 * come in_attesa_pagamento). Tracciamento approvazione/rifiuto admin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prenotazioni', function (Blueprint $table) {
            $table->dateTime('approvata_at')->nullable()->after('scadenza_pagamento_at');
            $table->uuid('approvata_da')->nullable()->after('approvata_at');
            $table->string('motivo_rifiuto')->nullable()->after('approvata_da');

            $table->foreign('approvata_da')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('prenotazioni', function (Blueprint $table) {
            $table->dropForeign(['approvata_da']);
            $table->dropColumn(['approvata_at', 'approvata_da', 'motivo_rifiuto']);
        });
    }
};
