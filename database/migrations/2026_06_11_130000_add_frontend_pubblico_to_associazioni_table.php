<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Frontend pubblico per-associazione (/a/{slug}):
 * - slug univoco (backfill dal nome, suffisso numerico su collisione);
 * - flag di attivazione del mini-sito eventi;
 * - descrizione pubblica mostrata sulla home brandizzata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('associazioni', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->after('nome');
            $table->boolean('frontend_pubblico_attivo')->default(false)->after('attiva');
            $table->text('descrizione_pubblica')->nullable()->after('frontend_pubblico_attivo');
        });

        // Backfill: slug dal nome, con suffisso -2, -3, ... su collisione
        $usati = DB::table('associazioni')->whereNotNull('slug')->pluck('slug')->all();

        DB::table('associazioni')->whereNull('slug')->orderBy('created_at')->orderBy('id')
            ->get(['id', 'nome'])
            ->each(function (object $riga) use (&$usati) {
                $base = Str::slug(Str::limit((string) $riga->nome, 90, '')) ?: 'associazione';
                $slug = $base;
                $i = 2;

                while (in_array($slug, $usati, true)) {
                    $slug = $base.'-'.$i;
                    $i++;
                }

                $usati[] = $slug;

                DB::table('associazioni')->where('id', $riga->id)->update(['slug' => $slug]);
            });

        Schema::table('associazioni', function (Blueprint $table) {
            $table->unique('slug');
        });
    }

    public function down(): void
    {
        Schema::table('associazioni', function (Blueprint $table) {
            $table->dropUnique(['slug']);
            $table->dropColumn(['slug', 'frontend_pubblico_attivo', 'descrizione_pubblica']);
        });
    }
};
