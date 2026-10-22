<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Limite per sede esplicito: se le sedi assegnate vengono eliminate l'utente
 * resta limitato (e non vede nulla) invece di tornare a vedere tutto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('utenti', function (Blueprint $table): void {
            $table->boolean('limitato_sedi')->default(false)->after('ruolo');
        });

        DB::table('utenti')->whereIn('id', DB::table('utente_sedi')->select('utente_id'))->update(['limitato_sedi' => true]);
    }

    public function down(): void
    {
        Schema::table('utenti', function (Blueprint $table): void {
            $table->dropColumn('limitato_sedi');
        });
    }
};
