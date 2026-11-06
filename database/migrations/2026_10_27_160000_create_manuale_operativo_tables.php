<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manuale operativo interno (issue #12): pagine con le procedure
 * dell'associazione, divise per sezione, con un elenco facoltativo di passi
 * da usare come checklist. Ogni "esecuzione" è una checklist avviata da una
 * pagina (es. "Apertura anno sociale 2027") con i passi spuntati. Un
 * adempimento ricorrente può rimandare alla procedura da seguire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manuale_pagine', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('sezione', 100);
            $table->string('titolo', 200);
            // Testo in Markdown semplice, ripulito in visualizzazione
            $table->longText('contenuto')->nullable();
            // Passi della checklist: ["Apri l'anno sociale", ...]
            $table->json('passi')->nullable();
            // Valori ammessi: vedi ManualePagina::VISIBILITA ('staff'|'soci')
            $table->string('visibilita', 20)->default('staff');
            $table->uuid('responsabile_utente_id')->nullable();
            $table->dateTime('revisionata_at')->nullable();
            $table->unsignedSmallInteger('ordine')->default(0);
            $table->timestamps();

            $table->index(['associazione_id', 'sezione', 'ordine'], 'idx_manuale_pagine_sezione');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('responsabile_utente_id')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('manuale_esecuzioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('pagina_id');
            $table->string('titolo', 200);
            $table->uuid('avviata_da')->nullable();
            $table->dateTime('completata_at')->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('pagina_id')->references('id')->on('manuale_pagine')->cascadeOnDelete();
            $table->foreign('avviata_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('manuale_esecuzioni_passi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('esecuzione_id');
            $table->unsignedSmallInteger('indice');
            // Copia del testo del passo all'avvio: la pagina può cambiare dopo
            $table->string('testo', 500);
            $table->dateTime('completato_at')->nullable();
            $table->uuid('completato_da')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['esecuzione_id', 'indice'], 'uq_manuale_esecuzioni_passi_indice');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('esecuzione_id')->references('id')->on('manuale_esecuzioni')->cascadeOnDelete();
            $table->foreign('completato_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::table('adempimenti', function (Blueprint $table) {
            $table->uuid('manuale_pagina_id')->nullable()->after('descrizione');

            $table->foreign('manuale_pagina_id')->references('id')->on('manuale_pagine')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('adempimenti', function (Blueprint $table) {
            $table->dropForeign(['manuale_pagina_id']);
            $table->dropColumn('manuale_pagina_id');
        });

        Schema::dropIfExists('manuale_esecuzioni_passi');
        Schema::dropIfExists('manuale_esecuzioni');
        Schema::dropIfExists('manuale_pagine');
    }
};
