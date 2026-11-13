<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invito amministratore monouso: il link firmato porta un nonce casuale il
 * cui hash è salvato qui; accettato una volta, il nonce viene azzerato e il
 * link (anche se ancora dentro i 7 giorni) non vale più. Un nuovo invito
 * sostituisce il precedente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utenti', function (Blueprint $table): void {
            $table->string('invito_token', 64)->nullable()->after('attivo');
            $table->timestamp('invito_scade_at')->nullable()->after('invito_token');
        });
    }

    public function down(): void
    {
        Schema::table('utenti', function (Blueprint $table): void {
            $table->dropColumn(['invito_token', 'invito_scade_at']);
        });
    }
};
