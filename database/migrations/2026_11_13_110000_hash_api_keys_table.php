<?php

use App\Services\ApiV1\BackfillHashChiaviApi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chiavi API salvate come hash (hardening audit run-1).
 *
 * La colonna `api_key` conteneva la chiave in chiaro: chi legge il database
 * (backup, dump, SQL injection) otteneva credenziali spendibili. Da ora si
 * persiste solo `api_key_hash` (sha256 esadecimale, 64 caratteri) e un
 * prefisso di 8 caratteri (`api_key_prefix`) per riconoscere la chiave in UI;
 * la chiave in chiaro si vede una sola volta, alla creazione.
 *
 * Backfill idempotente (BackfillHashChiaviApi): vengono hashate solo le
 * righe con `api_key_hash` ancora NULL, qualunque sia il formato della chiave
 * in chiaro (64 hex o altro); una riga già convertita non viene mai ri-hashata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('api_key_hash', 64)->nullable()->unique()->after('associazione_id');
            $table->string('api_key_prefix', 8)->nullable()->after('api_key_hash');
        });

        (new BackfillHashChiaviApi)->esegui();

        // L'indice univoco va rimosso prima della colonna (vincolo SQLite)
        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropUnique(['api_key']);
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn('api_key');
        });
    }

    public function down(): void
    {
        // La chiave in chiaro non è recuperabile dall'hash: la colonna torna
        // vuota e le chiavi esistenti vanno rigenerate.
        Schema::table('api_keys', function (Blueprint $table) {
            $table->string('api_key', 64)->nullable()->unique()->after('associazione_id');
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropUnique(['api_key_hash']);
        });

        Schema::table('api_keys', function (Blueprint $table) {
            $table->dropColumn(['api_key_hash', 'api_key_prefix']);
        });
    }
};
