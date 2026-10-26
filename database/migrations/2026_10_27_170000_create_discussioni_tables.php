<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Spazio di discussione tra soci (issue #13):
 * - discussioni_spazi: canali tematici aperti a tutti i soci attivi o a chi
 *   ha un tag;
 * - discussioni / discussioni_messaggi: thread e risposte (il primo
 *   messaggio è il testo di apertura); i messaggi si nascondono, non si
 *   cancellano;
 * - discussioni_segnalazioni: messaggi segnalati dai soci alla moderazione;
 * - discussioni_iscrizioni: chi segue una discussione e riceve il riepilogo;
 * - discussioni_profili: regolamento accettato e sospensione dalla scrittura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discussioni_spazi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome', 100);
            $table->text('descrizione')->nullable();
            // Valori ammessi: vedi DiscussioneSpazio::ACCESSI ('tutti'|'tag')
            $table->string('accesso', 20)->default('tutti');
            $table->uuid('tag_id')->nullable();
            $table->unsignedSmallInteger('ordine')->default(0);
            $table->boolean('archiviato')->default(false);
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->nullOnDelete();
        });

        Schema::create('discussioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('spazio_id');
            $table->uuid('socio_id');
            $table->string('titolo', 200);
            $table->boolean('fissata')->default(false);
            $table->boolean('chiusa')->default(false);
            $table->dateTime('ultima_attivita_at');
            $table->timestamps();

            $table->index(['spazio_id', 'fissata', 'ultima_attivita_at'], 'idx_discussioni_spazio_attivita');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('spazio_id')->references('id')->on('discussioni_spazi')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::create('discussioni_messaggi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('discussione_id');
            $table->uuid('socio_id');
            $table->text('testo');
            $table->dateTime('nascosto_at')->nullable();
            $table->uuid('nascosto_da')->nullable();
            $table->timestamps();

            $table->index(['discussione_id', 'created_at'], 'idx_discussioni_messaggi_discussione');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('discussione_id')->references('id')->on('discussioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('nascosto_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('discussioni_segnalazioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('messaggio_id');
            $table->uuid('socio_id');
            $table->string('motivo', 500)->nullable();
            $table->dateTime('gestita_at')->nullable();
            $table->timestamps();

            $table->unique(['messaggio_id', 'socio_id'], 'uq_discussioni_segnalazioni_socio');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('messaggio_id')->references('id')->on('discussioni_messaggi')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::create('discussioni_iscrizioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('discussione_id');
            $table->uuid('socio_id');
            // Messaggi successivi a questa data vanno nel prossimo riepilogo
            $table->dateTime('ultimo_riepilogo_at')->nullable();
            $table->timestamps();

            $table->unique(['discussione_id', 'socio_id'], 'uq_discussioni_iscrizioni_socio');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('discussione_id')->references('id')->on('discussioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::create('discussioni_profili', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id')->unique();
            $table->dateTime('regolamento_accettato_at')->nullable();
            $table->dateTime('sospeso_at')->nullable();
            $table->string('sospeso_motivo', 500)->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discussioni_profili');
        Schema::dropIfExists('discussioni_iscrizioni');
        Schema::dropIfExists('discussioni_segnalazioni');
        Schema::dropIfExists('discussioni_messaggi');
        Schema::dropIfExists('discussioni');
        Schema::dropIfExists('discussioni_spazi');
    }
};
