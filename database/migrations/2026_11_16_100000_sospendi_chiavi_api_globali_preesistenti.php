<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sospende le chiavi API globali presenti al momento dell'aggiornamento
 * (audit run-3, voce da validare confermata).
 *
 * Prima della 0.3.1 eliminare un'associazione lasciava le sue chiavi con
 * associazione_id NULL, cioè globali; la migration 2026_11_13_140500 le ha
 * marcate globale=true insieme alle globali vere, e nei dati non c'è modo di
 * distinguerle. Una chiave del genere legge e scrive i dati di tutte le
 * associazioni per conto di chi l'aveva (tipicamente l'ex admin
 * dell'associazione eliminata).
 *
 * Fail closed: tutte le chiavi globali esistenti vengono disattivate. Il super
 * admin riattiva dalla pagina Chiavi API quelle che riconosce; un'integrazione
 * che usa una chiave globale legittima resta ferma fino ad allora. Le chiavi
 * delle singole associazioni non cambiano.
 */
return new class extends Migration
{
    public function up(): void
    {
        $chiavi = DB::table('api_keys')
            ->where('globale', true)
            ->whereNull('associazione_id')
            ->where('attiva', true);

        $sospese = (clone $chiavi)->pluck('nome', 'id');

        if ($sospese->isEmpty()) {
            return;
        }

        $chiavi->update(['attiva' => false, 'updated_at' => now()]);

        Log::warning('Chiavi API globali sospese dall\'aggiornamento: riattivare dalla pagina Chiavi API solo quelle riconosciute', [
            'chiavi' => $sospese->all(),
        ]);
    }

    public function down(): void
    {
        // Nessun ripristino automatico: quali chiavi fossero attive prima non
        // è registrato, e riattivarle tutte annullerebbe la correzione.
    }
};
