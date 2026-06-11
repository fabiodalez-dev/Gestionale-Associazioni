<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo iscrizioni (PIANO-ISCRIZIONI §Schema dati):
 * - iscrizioni_aperte: chiusura manuale delle prenotazioni, oltre alla
 *   finestra iscrizioni_dal/al già esistente (entra in Evento::isPrenotabile);
 * - richiede_approvazione: la prenotazione resta 'in_approvazione' finché
 *   un admin non la approva/rifiuta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventi', function (Blueprint $table) {
            $table->boolean('iscrizioni_aperte')->default(true)->after('iscrizioni_al');
            $table->boolean('richiede_approvazione')->default(false)->after('iscrizioni_aperte');
        });
    }

    public function down(): void
    {
        Schema::table('eventi', function (Blueprint $table) {
            $table->dropColumn(['iscrizioni_aperte', 'richiede_approvazione']);
        });
    }
};
