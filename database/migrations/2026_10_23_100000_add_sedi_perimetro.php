<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Perimetro per sede (Fase 8):
 * - utente_sedi: sedi assegnate a un utente dello staff (nessuna = tutte);
 * - eventi.sede_id e movimenti.sede_id facoltativi: NULL = di tutta l'associazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utente_sedi', function (Blueprint $table): void {
            $table->uuid('utente_id');
            $table->uuid('sede_id');
            $table->timestamps();

            $table->primary(['utente_id', 'sede_id']);
            $table->index('sede_id');
            $table->foreign('utente_id')->references('id')->on('utenti')->cascadeOnDelete();
            $table->foreign('sede_id')->references('id')->on('sedi')->cascadeOnDelete();
        });

        foreach (['eventi', 'movimenti'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table): void {
                $table->uuid('sede_id')->nullable()->after('associazione_id')->index();
                $table->foreign('sede_id')->references('id')->on('sedi')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['eventi', 'movimenti'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table): void {
                $table->dropForeign(['sede_id']);
                $table->dropColumn('sede_id');
            });
        }

        Schema::dropIfExists('utente_sedi');
    }
};
