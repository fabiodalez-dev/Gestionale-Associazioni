<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addendum "Convocazioni assemblee + Trasparenza" (piano Gestione 2).
 * Migration additiva (SQLite-compatibile):
 *
 * - annunci: tipo ('comunicazione'|'convocazione', vedi Annuncio::TIPI),
 *   dettagli assemblea (data/luogo/ordine del giorno), evento collegato
 *   opzionale per la conferma di presenza, timestamp di invio della
 *   convocazione via email ai soci;
 * - documenti: flag 'pubblico' + categoria_pubblica per la pagina
 *   Trasparenza del mini-sito (/a/{slug}/trasparenza, senza auth).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('annunci', function (Blueprint $table) {
            // Valori ammessi: vedi Annuncio::TIPI ('comunicazione'|'convocazione')
            $table->string('tipo', 20)->default('comunicazione')->after('titolo');
            // Dettagli dell'assemblea (solo per tipo 'convocazione')
            $table->dateTime('data_assemblea')->nullable()->after('corpo_html');
            $table->string('luogo_assemblea')->nullable()->after('data_assemblea');
            $table->text('ordine_del_giorno')->nullable()->after('luogo_assemblea');
            // Evento collegato (i soci confermano la presenza dalla pagina pubblica)
            $table->uuid('evento_id')->nullable()->after('ordine_del_giorno');
            // Quando l'admin ha inviato la convocazione via email ai soci attivi
            $table->timestamp('convocazione_inviata_at')->nullable()->after('evento_id');

            $table->foreign('evento_id')->references('id')->on('eventi')->nullOnDelete();
        });

        Schema::table('documenti', function (Blueprint $table) {
            // Documento visibile sulla pagina Trasparenza del mini-sito
            $table->boolean('pubblico')->default(false)->after('categoria');
            // Valori ammessi: vedi Documento::CATEGORIE_PUBBLICHE
            // ('bilancio'|'statuto'|'regolamento'|'verbale'|'altro')
            $table->string('categoria_pubblica', 30)->nullable()->after('pubblico');
        });
    }

    public function down(): void
    {
        Schema::table('documenti', function (Blueprint $table) {
            $table->dropColumn(['pubblico', 'categoria_pubblica']);
        });

        Schema::table('annunci', function (Blueprint $table) {
            $table->dropForeign(['evento_id']);
            $table->dropColumn([
                'tipo',
                'data_assemblea',
                'luogo_assemblea',
                'ordine_del_giorno',
                'evento_id',
                'convocazione_inviata_at',
            ]);
        });
    }
};
