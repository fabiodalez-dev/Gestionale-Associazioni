<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * I token Sanctum creati prima della scadenza obbligatoria (365 giorni alla
 * creazione) non hanno expires_at: ricevono un anno di validità da ora, così
 * il limite di sistema (config sanctum.expiration) non li taglia
 * retroattivamente e le integrazioni esistenti continuano a funzionare.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('personal_access_tokens')) {
            return;
        }

        DB::table('personal_access_tokens')
            ->whereNull('expires_at')
            ->update(['expires_at' => now()->addDays(365)]);
    }

    public function down(): void
    {
        // Le scadenze assegnate non sono distinguibili da quelle scelte a mano: nessun rollback
    }
};
