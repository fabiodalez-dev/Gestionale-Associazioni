<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondamenta del piano "Vita sociale" (docs/PIANO-VITA-SOCIALE.md).
 * Migration UNICA e additiva (SQLite-compatibile) per tutti i moduli:
 *
 * - f.3  associazioni.promemoria_rinnovo_config (json, default applicativo [30,15,3])
 *        + tabella promemoria_inviati (anti-duplicazione invii multi-step);
 * - f.8  eventi.serie_id (uuid condiviso fra le occorrenze di una serie ricorrente);
 * - f.10 eventi.solo_soci (evento riservato ai soci attivi);
 * - f.11 tabella feedback_eventi (un feedback per prenotazione, link via token);
 * - f.14 richieste_iscrizione.delibera_numero/delibera_data;
 * - f.15 tabella cessazioni (dimissioni/decadenza/espulsione/decesso);
 * - f.16 tabella cariche (organi sociali, mandati);
 * - f.17 verbali.numero/anno (numerazione per anno) + verbali.evento_id;
 * - f.21 tabella ricevute (ricevuta numerata per anno della quota pagata);
 * - f.23 documenti_socio.tipo_documento (la scadenza usa la colonna
 *        GIÀ esistente documenti_socio.data_scadenza — non duplicata).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('associazioni', function (Blueprint $table) {
            // Step promemoria rinnovo in giorni prima della scadenza tessera,
            // es. [30, 15, 3]. NULL = default applicativo (Associazione::PROMEMORIA_RINNOVO_DEFAULT)
            $table->json('promemoria_rinnovo_config')->nullable()->after('iscrizione_form_config');
        });

        Schema::table('eventi', function (Blueprint $table) {
            // Serie di eventi ricorrenti: uuid condiviso fra tutte le occorrenze
            $table->uuid('serie_id')->nullable()->after('immagine_url')->index();
            // Evento riservato ai soci attivi (prenotabile solo da account collegati a socio)
            $table->boolean('solo_soci')->default(false)->after('serie_id');
        });

        Schema::create('promemoria_inviati', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id');
            $table->uuid('tessera_id')->nullable();
            // Valori ammessi: vedi PromemoriaInviato::TIPO_* (es. 'rinnovo_tessera', 'documento_scadenza')
            $table->string('tipo', 50);
            // Step in giorni prima della scadenza (es. 30, 15, 3)
            $table->unsignedSmallInteger('step');
            $table->dateTime('inviato_at');
            $table->timestamps();

            $table->index(['associazione_id', 'tipo', 'inviato_at'], 'idx_promemoria_assoc_tipo_data');
            $table->unique(['socio_id', 'tessera_id', 'tipo', 'step'], 'uq_promemoria_socio_tessera_tipo_step');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('tessera_id')->references('id')->on('tessere')->cascadeOnDelete();
        });

        Schema::create('feedback_eventi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('evento_id');
            // Un solo feedback per prenotazione (f.11)
            $table->uuid('prenotazione_id')->unique();
            // 1..5; NULL finché il form non viene compilato
            $table->unsignedTinyInteger('voto')->nullable();
            $table->text('commento')->nullable();
            // Token del link pubblico al form di valutazione
            $table->char('token', 64)->unique();
            $table->dateTime('inviato_at')->nullable();
            $table->dateTime('compilato_at')->nullable();
            $table->timestamps();

            $table->index(['evento_id', 'compilato_at'], 'idx_feedback_evento_compilato');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('evento_id')->references('id')->on('eventi')->cascadeOnDelete();
            $table->foreign('prenotazione_id')->references('id')->on('prenotazioni')->cascadeOnDelete();
        });

        Schema::table('richieste_iscrizione', function (Blueprint $table) {
            // Delibera di ammissione (opzionale, f.14)
            $table->string('delibera_numero', 50)->nullable()->after('gestita_at');
            $table->date('delibera_data')->nullable()->after('delibera_numero');
        });

        Schema::create('cessazioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id');
            // Valori ammessi: vedi Cessazione::TIPI ('dimissioni'|'decadenza'|'espulsione'|'decesso')
            $table->string('tipo', 30);
            $table->date('data');
            $table->text('motivo')->nullable();
            $table->string('delibera_numero', 50)->nullable();
            $table->date('delibera_data')->nullable();
            $table->uuid('registrata_da')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'data'], 'idx_cessazioni_assoc_data');
            $table->index('socio_id');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('registrata_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('cariche', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id');
            // Ruolo libero, suggerimenti in Carica::RUOLI (Presidente, Segretario, ...)
            $table->string('ruolo', 100);
            $table->date('inizio');
            // NULL = mandato in corso senza termine fissato
            $table->date('fine')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'fine'], 'idx_cariche_assoc_fine');
            $table->index('socio_id');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::table('verbali', function (Blueprint $table) {
            // Numerazione automatica per anno: es. numero 3, anno 2026 → "3/2026" (f.17)
            $table->integer('numero')->nullable()->after('titolo');
            $table->integer('anno')->nullable()->after('numero');
            // Collegamento opzionale all'evento (es. assemblea)
            $table->uuid('evento_id')->nullable()->after('data_approvazione')->index();

            $table->unique(['associazione_id', 'anno', 'numero'], 'uq_verbali_assoc_anno_numero');
            $table->foreign('evento_id')->references('id')->on('eventi')->nullOnDelete();
        });

        Schema::create('ricevute', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            // Una sola ricevuta per quota (f.21)
            $table->uuid('quota_id')->unique();
            // Numero progressivo per tenant e anno: es. 12/2026
            $table->integer('numero');
            $table->integer('anno');
            $table->decimal('importo', 10, 2);
            $table->dateTime('emessa_at');
            $table->timestamps();

            $table->unique(['associazione_id', 'anno', 'numero'], 'uq_ricevute_assoc_anno_numero');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('quota_id')->references('id')->on('quote')->cascadeOnDelete();
        });

        Schema::table('documenti_socio', function (Blueprint $table) {
            // Tipo libero, suggerimenti in DocumentoSocio::TIPI_DOCUMENTO.
            // La scadenza del documento usa la colonna esistente data_scadenza.
            $table->string('tipo_documento', 100)->nullable()->after('descrizione');
        });
    }

    public function down(): void
    {
        Schema::table('documenti_socio', function (Blueprint $table) {
            $table->dropColumn('tipo_documento');
        });

        Schema::dropIfExists('ricevute');

        Schema::table('verbali', function (Blueprint $table) {
            $table->dropForeign(['evento_id']);
            $table->dropUnique('uq_verbali_assoc_anno_numero');
            $table->dropColumn(['numero', 'anno', 'evento_id']);
        });

        Schema::dropIfExists('cariche');
        Schema::dropIfExists('cessazioni');

        Schema::table('richieste_iscrizione', function (Blueprint $table) {
            $table->dropColumn(['delibera_numero', 'delibera_data']);
        });

        Schema::dropIfExists('feedback_eventi');
        Schema::dropIfExists('promemoria_inviati');

        Schema::table('eventi', function (Blueprint $table) {
            $table->dropColumn(['serie_id', 'solo_soci']);
        });

        Schema::table('associazioni', function (Blueprint $table) {
            $table->dropColumn('promemoria_rinnovo_config');
        });
    }
};
