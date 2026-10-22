<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondamenta del piano "Gestione 2" (docs/PIANO-GESTIONE-2.md).
 * Migration UNICA e additiva (SQLite-compatibile) per tutti i moduli:
 *
 * - f.3  tabella note_socio (note interne admin sulla scheda socio, pin opzionale);
 * - f.4  tabella attivita_admin (promemoria/attività con assegnatario e
 *        collegamento polimorfico opzionale "notabile" a socio/evento);
 * - f.7  tabella annunci (bacheca, finestra di pubblicazione, solo_soci);
 * - f.8  tabella newsletter_inviate (storico newsletter composte da annunci);
 * - f.9  associazioni.auguri_automatici (toggle auguri di compleanno per tenant);
 * - f.11 tabelle sondaggi + risposte_sondaggio (votante polimorfico socio/account,
 *        un voto per sondaggio+votante);
 * - f.13 tabelle categorie_movimento + movimenti (prima nota, origine polimorfica
 *        opzionale quota/prenotazione/donazione, unica per idempotenza);
 * - f.15 tabella donazioni (pagamento gateway via interfaccia Pagabile);
 * - f.17 eventi.budget + tabella spese_evento (economia evento);
 * - f.19 tabella richieste_modifica_dati (proposte json, origine polimorfica
 *        socio/account, gestione admin);
 * - f.20 tabella nuclei + soci.nucleo_id (nuclei familiari, capofamiglia);
 * - f.22 soci.tutore_nome/tutore_telefono/tutore_email (soci minorenni);
 * - f.25 tabella accessi (audit Login/Failed/Logout per tutte le guard).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('associazioni', function (Blueprint $table) {
            // f.9: invio automatico degli auguri di compleanno ai soci attivi
            $table->boolean('auguri_automatici')->default(false)->after('promemoria_rinnovo_config');
        });

        Schema::create('note_socio', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id');
            // Admin autore della nota (NULL se l'utente è stato eliminato)
            $table->uuid('autore_id')->nullable();
            $table->text('testo');
            // Nota fissata in cima alla timeline (pin opzionale, f.3)
            $table->boolean('fissata')->default(false);
            $table->timestamps();

            $table->index(['socio_id', 'fissata'], 'idx_note_socio_socio_fissata');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('autore_id')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('attivita_admin', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('titolo');
            $table->text('descrizione')->nullable();
            // Scadenza dell'attività (promemoria email il giorno stesso, f.4)
            $table->date('scadenza')->nullable();
            // Utente assegnatario (NULL = non assegnata)
            $table->uuid('assegnatario_id')->nullable()->index();
            // Collegamento polimorfico opzionale a Socio o Evento
            $table->nullableUuidMorphs('notabile');
            // Valori ammessi: vedi AttivitaAdmin::STATI ('aperta'|'completata')
            $table->string('stato', 20)->default('aperta');
            $table->dateTime('completata_at')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'stato', 'scadenza'], 'idx_attivita_assoc_stato_scadenza');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('assegnatario_id')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('annunci', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('titolo');
            // HTML purificato lato modulo prima del salvataggio (f.7)
            $table->text('corpo_html');
            // Finestra di pubblicazione: NULL dal = bozza, NULL al = senza scadenza
            $table->dateTime('pubblicato_dal')->nullable();
            $table->dateTime('pubblicato_al')->nullable();
            $table->boolean('in_evidenza')->default(false);
            // Visibile solo nell'area riservata soci (f.7)
            $table->boolean('solo_soci')->default(false);
            $table->uuid('creato_da')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'pubblicato_dal', 'pubblicato_al'], 'idx_annunci_pubblicazione');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('creato_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('newsletter_inviate', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('titolo');
            // Id (uuid) degli annunci inclusi nella newsletter (f.8)
            $table->json('annunci_ids');
            // Numero di destinatari a cui è stata accodata
            $table->unsignedInteger('inviata_a')->default(0);
            $table->dateTime('inviata_at')->nullable();
            $table->uuid('inviata_da')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'inviata_at'], 'idx_newsletter_assoc_data');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('inviata_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('sondaggi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('domanda', 500);
            // Lista di opzioni di risposta; la risposta referenzia l'indice (f.11)
            $table->json('opzioni');
            // Finestra di apertura del voto: NULL dal = bozza, NULL al = senza chiusura
            $table->dateTime('aperto_dal')->nullable();
            $table->dateTime('aperto_al')->nullable();
            // Votabile solo dall'area soci (esclude gli account frontend)
            $table->boolean('solo_soci')->default(false);
            $table->timestamps();

            $table->index(['associazione_id', 'aperto_dal', 'aperto_al'], 'idx_sondaggi_apertura');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });

        Schema::create('risposte_sondaggio', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('sondaggio_id');
            // Votante polimorfico: Socio (area soci) o Account (frontend pubblico)
            $table->uuidMorphs('votante');
            // Indice (0-based) dell'opzione scelta dentro sondaggi.opzioni
            $table->unsignedSmallInteger('opzione');
            $table->timestamps();

            // Un solo voto per votante per sondaggio (f.11)
            $table->unique(['sondaggio_id', 'votante_type', 'votante_id'], 'uq_risposta_sondaggio_votante');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('sondaggio_id')->references('id')->on('sondaggi')->cascadeOnDelete();
        });

        Schema::create('categorie_movimento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome', 100);
            // Valori ammessi: vedi CategoriaMovimento::TIPI ('entrata'|'uscita')
            $table->string('tipo', 20);
            $table->timestamps();

            $table->unique(['associazione_id', 'nome', 'tipo'], 'uq_categorie_movimento_nome_tipo');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });

        Schema::create('donazioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            // Dati del donatore (facoltativi se anonima)
            $table->string('donatore_nome', 150)->nullable();
            $table->string('donatore_email')->nullable();
            $table->decimal('importo', 10, 2);
            // Non mostrare il nome negli elenchi pubblici (f.15)
            $table->boolean('anonimo')->default(false);
            // Valori ammessi: vedi Donazione::STATI ('in_attesa'|'pagata'|'annullata')
            $table->string('stato', 20)->default('in_attesa');
            $table->string('gateway', 20)->nullable();
            $table->string('transaction_id')->nullable();
            $table->dateTime('pagata_at')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'stato'], 'idx_donazioni_assoc_stato');
            $table->index(['associazione_id', 'pagata_at'], 'idx_donazioni_assoc_pagata');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });

        Schema::create('movimenti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            // Valori ammessi: vedi Movimento::TIPI ('entrata'|'uscita')
            $table->string('tipo', 20);
            $table->decimal('importo', 10, 2);
            $table->date('data');
            // Categoria seedata per tenant (NULL se la categoria è stata eliminata)
            $table->uuid('categoria_id')->nullable()->index();
            $table->string('descrizione', 500)->nullable();
            // Metodo di incasso/pagamento libero (contanti, bonifico, stripe, paypal, ...)
            $table->string('metodo', 50)->nullable();
            // Origine polimorfica opzionale: Quota, Prenotazione o Donazione (f.13)
            $table->nullableUuidMorphs('origine');
            $table->uuid('registrato_da')->nullable();
            $table->timestamps();

            // Idempotenza dei movimenti automatici: una sola riga per origine
            $table->unique(['origine_type', 'origine_id'], 'uq_movimenti_origine');
            $table->index(['associazione_id', 'data'], 'idx_movimenti_assoc_data');
            $table->index(['associazione_id', 'tipo', 'data'], 'idx_movimenti_assoc_tipo_data');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('categoria_id')->references('id')->on('categorie_movimento')->nullOnDelete();
            $table->foreign('registrato_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::table('eventi', function (Blueprint $table) {
            // Budget previsto dell'evento (f.17); NULL = non gestito
            $table->decimal('budget', 10, 2)->nullable()->after('prezzo');
        });

        Schema::create('spese_evento', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('evento_id');
            $table->string('descrizione');
            $table->decimal('importo', 10, 2);
            $table->date('data');
            $table->timestamps();

            $table->index(['evento_id', 'data'], 'idx_spese_evento_data');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('evento_id')->references('id')->on('eventi')->cascadeOnDelete();
        });

        Schema::create('richieste_modifica_dati', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('socio_id');
            // Campi anagrafici proposti: {campo: nuovo_valore, ...} (f.19)
            $table->json('proposte');
            // Valori ammessi: vedi RichiestaModificaDati::STATO_* ('in_attesa'|'approvata'|'rifiutata')
            $table->string('stato', 20)->default('in_attesa');
            // Chi ha proposto la modifica: Socio (area soci) o Account collegato
            $table->nullableUuidMorphs('origine');
            $table->uuid('gestita_da')->nullable();
            $table->dateTime('gestita_at')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'stato'], 'idx_richieste_modifica_stato');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('gestita_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('nuclei', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome', 150);
            // Capofamiglia (socio); NULL se il socio è stato eliminato
            $table->uuid('capofamiglia_id')->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('capofamiglia_id')->references('id')->on('soci')->nullOnDelete();
        });

        Schema::table('soci', function (Blueprint $table) {
            // f.20: nucleo familiare di appartenenza
            $table->uuid('nucleo_id')->nullable()->after('categoria_socio_id')->index();
            // f.22: tutore per i soci minorenni
            $table->string('tutore_nome', 150)->nullable()->after('nucleo_id');
            $table->string('tutore_telefono', 30)->nullable()->after('tutore_nome');
            $table->string('tutore_email')->nullable()->after('tutore_telefono');

            $table->foreign('nucleo_id')->references('id')->on('nuclei')->nullOnDelete();
        });

        Schema::create('accessi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Tenant dell'utente autenticato/trovato; NULL se non determinabile
            // (es. tentativo fallito su email sconosciuta) o super_admin
            $table->uuid('associazione_id')->nullable()->index();
            // Guard di autenticazione: 'web' (admin), 'socio', 'account'
            $table->string('guard', 20);
            // Utente/Socio/Account autenticato; NULL su tentativo fallito senza match
            $table->nullableUuidMorphs('autenticabile');
            $table->string('email_tentata')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            // Valori ammessi: vedi Accesso::ESITI ('login'|'fallito'|'logout')
            $table->string('esito', 20);
            $table->timestamps();

            // f.25: alert dopo N tentativi falliti in finestra temporale sulla stessa email
            $table->index(['email_tentata', 'created_at'], 'idx_accessi_email_data');
            $table->index(['associazione_id', 'created_at'], 'idx_accessi_assoc_data');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accessi');

        Schema::table('soci', function (Blueprint $table) {
            $table->dropForeign(['nucleo_id']);
            $table->dropColumn(['nucleo_id', 'tutore_nome', 'tutore_telefono', 'tutore_email']);
        });

        Schema::dropIfExists('nuclei');
        Schema::dropIfExists('richieste_modifica_dati');
        Schema::dropIfExists('spese_evento');

        Schema::table('eventi', function (Blueprint $table) {
            $table->dropColumn('budget');
        });

        Schema::dropIfExists('movimenti');
        Schema::dropIfExists('donazioni');
        Schema::dropIfExists('categorie_movimento');
        Schema::dropIfExists('risposte_sondaggio');
        Schema::dropIfExists('sondaggi');
        Schema::dropIfExists('newsletter_inviate');
        Schema::dropIfExists('annunci');
        Schema::dropIfExists('attivita_admin');
        Schema::dropIfExists('note_socio');

        Schema::table('associazioni', function (Blueprint $table) {
            $table->dropColumn('auguri_automatici');
        });
    }
};
