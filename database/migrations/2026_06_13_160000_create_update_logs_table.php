<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storico degli aggiornamenti applicati dalla dashboard (super_admin):
 * versione di partenza/arrivo, esito, backup creato, errore ed esecutore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('update_logs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('from_version', 30);
            $table->string('to_version', 30);
            $table->string('stato', 20)->default('avviato'); // avviato|completato|fallito|rollback
            $table->string('backup_path', 500)->nullable();
            $table->text('messaggio_errore')->nullable();
            $table->uuid('eseguito_da')->nullable();
            $table->timestamp('avviato_at')->useCurrent();
            $table->timestamp('completato_at')->nullable();

            $table->index(['stato', 'avviato_at']);
            $table->foreign('eseguito_da')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('update_logs');
    }
};
