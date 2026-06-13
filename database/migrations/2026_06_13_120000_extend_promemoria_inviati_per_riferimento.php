<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estende promemoria_inviati per una deduplica robusta dei promemoria legati
 * a un'entità specifica (turno, adempimento), senza dipendere da hash troncati:
 *
 *  - riferimento_id: id dell'entità di riferimento (turno_id, adempimento_id),
 *    usato dalla deduplica applicativa così da non collidere mai tra entità
 *    diverse (niente più hash troncato dello step) — fix code-review #15.
 *  - socio_id diventa nullable: i promemoria adempimenti sono indirizzati agli
 *    admin del tenant, non a un socio (fix code-review #14).
 *  - step passa a unsignedInteger: alcuni tipi memorizzano l'anno (es. 2026)
 *    o altri valori oltre il range smallint; evita il fallimento dell'INSERT
 *    dopo l'invio email.
 *
 * L'unique esistente (socio_id, tessera_id, tipo, step) NON viene toccato:
 * resta la protezione DB per i promemoria con tessera (rinnovo/documento).
 * Per i tipi turno/adempimento (tessera_id NULL → unique inerte, NULL distinti
 * a DB) la deduplica è e resta applicativa, su riferimento_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promemoria_inviati', function (Blueprint $table): void {
            $table->uuid('socio_id')->nullable()->change();
            $table->unsignedInteger('step')->change();
            $table->uuid('riferimento_id')->nullable()->after('tessera_id');
            $table->index(['associazione_id', 'tipo', 'riferimento_id'], 'idx_promemoria_assoc_tipo_rif');
        });
    }

    public function down(): void
    {
        Schema::table('promemoria_inviati', function (Blueprint $table): void {
            $table->dropIndex('idx_promemoria_assoc_tipo_rif');
            $table->dropColumn('riferimento_id');
            $table->unsignedSmallInteger('step')->change();
            $table->uuid('socio_id')->nullable(false)->change();
        });
    }
};
