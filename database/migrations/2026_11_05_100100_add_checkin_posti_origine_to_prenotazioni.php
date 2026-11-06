<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Check-in per singolo posto e origine del check-in delle prenotazioni.
 *
 * - posti_entrati: posti della prenotazione già entrati (0..posti). La
 *   scansione del biglietto e le presenze in blocco registrano tutti i posti
 *   rimanenti, il check-in con la tessera (backoffice, kiosk, API v1) solo
 *   quello del socio; checkin_at resta l'orario del PRIMO ingresso
 *   (checkin_at valorizzato <=> posti_entrati > 0).
 * - checkin_origine: biglietto | presenze_blocco | tessera | api. Le origini
 *   "sincronizzate" (tessera, api) vengono annullate quando il partecipante
 *   socio viene rimosso o non è più Confermato.
 *
 * Dati esistenti: i check-in già registrati valgono per tutti i posti
 * (posti_entrati = posti, come venivano contati finora) e restano senza
 * origine: non risultano sincronizzati e nessun annullamento automatico li
 * tocca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prenotazioni', function (Blueprint $table): void {
            $table->unsignedTinyInteger('posti_entrati')->default(0)->after('checkin_at');
            $table->string('checkin_origine', 20)->nullable()->after('posti_entrati');
        });

        DB::table('prenotazioni')
            ->whereNotNull('checkin_at')
            ->update(['posti_entrati' => DB::raw('posti')]);
    }

    public function down(): void
    {
        Schema::table('prenotazioni', function (Blueprint $table): void {
            $table->dropColumn(['posti_entrati', 'checkin_origine']);
        });
    }
};
