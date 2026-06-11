<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estensione eventi per il sistema di prenotazione pubblico.
 * Nessun contatore denormalizzato: i posti occupati sono sempre
 * calcolati con SUM(posti) sulle prenotazioni attive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventi', function (Blueprint $table) {
            // Valori ammessi: 'bozza' | 'pubblicato' | 'archiviato' (niente enum nativo: compat SQLite)
            $table->string('stato_pubblicazione', 20)->default('bozza')->after('luogo');
            $table->unsignedInteger('capienza')->nullable()->after('stato_pubblicazione'); // null = illimitata
            $table->decimal('prezzo', 10, 2)->default(0)->after('capienza');
            $table->dateTime('iscrizioni_dal')->nullable()->after('prezzo');
            $table->dateTime('iscrizioni_al')->nullable()->after('iscrizioni_dal');
            $table->unsignedTinyInteger('max_posti_per_prenotazione')->default(4)->after('iscrizioni_al');
            $table->boolean('lista_attesa_attiva')->default(true)->after('max_posti_per_prenotazione');
            $table->unsignedSmallInteger('annullamento_entro_ore')->default(24)->after('lista_attesa_attiva');
            $table->text('immagine_url')->nullable()->after('annullamento_entro_ore');
            $table->dateTime('data_fine')->nullable()->after('data_evento');

            $table->index(
                ['associazione_id', 'stato_pubblicazione', 'data_evento'],
                'eventi_assoc_stato_data_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('eventi', function (Blueprint $table) {
            $table->dropIndex('eventi_assoc_stato_data_index');
            $table->dropColumn([
                'stato_pubblicazione',
                'capienza',
                'prezzo',
                'iscrizioni_dal',
                'iscrizioni_al',
                'max_posti_per_prenotazione',
                'lista_attesa_attiva',
                'annullamento_entro_ore',
                'immagine_url',
                'data_fine',
            ]);
        });
    }
};
