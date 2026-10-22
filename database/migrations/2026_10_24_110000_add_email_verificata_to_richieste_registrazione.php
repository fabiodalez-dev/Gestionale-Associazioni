<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conferma dell'email dell'amministratore: finché manca, la richiesta
 * non arriva al super admin, non attiva nulla e non occupa email o codici.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('richieste_registrazione', function (Blueprint $table): void {
            $table->timestamp('email_verificata_at')->nullable()->after('privacy_accettata_at');
        });
    }

    public function down(): void
    {
        Schema::table('richieste_registrazione', function (Blueprint $table): void {
            $table->dropColumn('email_verificata_at');
        });
    }
};
