<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fondamenta del piano "Organizzazione 3" (docs/PIANO-ORGANIZZAZIONE-3.md).
 * Migration UNICA e additiva (SQLite-compatibile) per tutti i moduli:
 *
 * - f.1  soci.disponibilita (json giorni/fasce) + soci.volontario_attivo;
 * - f.2  tabelle mansioni + socio_mansioni (tassonomia competenze per tenant);
 * - f.3  tabelle turni + turni_assegnazioni (pianificazione volontari,
 *        unique turno+socio, ore_effettive per il registro f.5);
 * - f.4  turni.auto_conferma + turni_assegnazioni.motivo_rinuncia;
 * - f.7  tabella spazi (anagrafica sale/locali, sede opzionale);
 * - f.8  tabella prenotazioni_spazi (uso interno con rilevazione conflitti);
 * - f.9  tabella attrezzature (inventario con stato e ubicazione);
 * - f.10 tabella prestiti_attrezzature (disponibilità calcolata, restituzione);
 * - f.11 eventi.spazio_id (controllo conflitti evento/prenotazioni);
 * - f.12 tabella progetti (con responsabile, stato e budget);
 * - f.13 movimenti.progetto_id (rendiconto prima nota per progetto);
 * - f.14 eventi.progetto_id + turni.progetto_id (collegamenti al progetto);
 * - f.16 tabella milestone_progetto (checklist con avanzamento);
 * - f.17 tabelle riunioni + riunioni_partecipanti (OdG json, inviti alle
 *        cariche del round 1, collegamento al Verbale generato);
 * - f.18 tabella votazioni_riunione (esito per punto OdG, unique riunione+punto);
 * - f.20 tabelle adempimenti + adempimenti_anni (ricorrenze annuali, checklist
 *        per anno dentro lo Scadenzario esistente);
 * - f.21 tabella contatti_esterni (mini-CRM fornitori/enti/sponsor/stampa);
 * - f.22 tabella sponsor (livelli, periodo, visibilità sul mini-sito);
 * - f.23 soci.codice_referral (slug univoco "porta un amico") +
 *        richieste_iscrizione.referral_codice;
 * - f.24 eventi.galleria_pubblica (galleria foto medialibrary 'galleria').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('soci', function (Blueprint $table) {
            // f.1: disponibilità volontariato {giorno: [mattina|pomeriggio|sera], ...}
            $table->json('disponibilita')->nullable()->after('tutore_email');
            $table->boolean('volontario_attivo')->default(false)->after('disponibilita');
            // f.23: codice referral personale "porta un amico" (slug univoco)
            $table->string('codice_referral', 30)->nullable()->after('volontario_attivo');

            $table->unique('codice_referral', 'uq_soci_codice_referral');
        });

        Schema::table('richieste_iscrizione', function (Blueprint $table) {
            // f.23: codice referral del socio che ha invitato (?ref=CODICE sul form pubblico)
            $table->string('referral_codice', 30)->nullable()->after('delibera_data')->index();
        });

        Schema::create('mansioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome', 100);
            $table->text('descrizione')->nullable();
            $table->timestamps();

            $table->unique(['associazione_id', 'nome'], 'uq_mansioni_assoc_nome');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });

        Schema::create('socio_mansioni', function (Blueprint $table) {
            // Pivot puro come socio_tags: il tenant è garantito da soci/mansioni
            $table->uuid('socio_id');
            $table->uuid('mansione_id')->index();
            $table->timestamps();

            // Una mansione assegnata una sola volta per socio (f.2)
            $table->primary(['socio_id', 'mansione_id']);

            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('mansione_id')->references('id')->on('mansioni')->cascadeOnDelete();
        });

        Schema::create('spazi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome', 150);
            $table->text('descrizione')->nullable();
            $table->unsignedInteger('capienza')->nullable();
            // Sede di appartenenza opzionale (f.7)
            $table->uuid('sede_id')->nullable();
            $table->boolean('attivo')->default(true);
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('sede_id')->references('id')->on('sedi')->nullOnDelete();
        });

        Schema::create('prenotazioni_spazi', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('spazio_id');
            // Titolo/uso della prenotazione interna (f.8)
            $table->string('titolo', 200);
            $table->dateTime('dal');
            $table->dateTime('al');
            // Utente che ha richiesto la prenotazione (NULL se eliminato)
            $table->uuid('richiedente_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // Ricerca conflitti di overlap sullo stesso spazio (f.8)
            $table->index(['spazio_id', 'dal', 'al'], 'idx_prenotazioni_spazi_periodo');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('spazio_id')->references('id')->on('spazi')->cascadeOnDelete();
            $table->foreign('richiedente_id')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('attrezzature', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome', 150);
            $table->text('descrizione')->nullable();
            $table->unsignedInteger('quantita')->default(1);
            $table->decimal('valore_unitario', 10, 2)->nullable();
            // Valori ammessi: vedi Attrezzatura::STATI ('buono'|'da_riparare'|'fuori_uso')
            $table->string('stato', 20)->default('buono');
            // Ubicazione: spazio censito (FK) e/o descrizione libera (f.9)
            $table->uuid('spazio_id')->nullable();
            $table->string('ubicazione', 200)->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('spazio_id')->references('id')->on('spazi')->nullOnDelete();
        });

        Schema::create('prestiti_attrezzature', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('attrezzatura_id');
            $table->uuid('socio_id');
            $table->unsignedInteger('quantita')->default(1);
            $table->date('dal');
            $table->date('restituzione_prevista');
            // NULL = prestito ancora attivo (f.10)
            $table->dateTime('restituito_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            // Disponibilità calcolata: quantita - prestiti attivi (f.10)
            $table->index(['attrezzatura_id', 'restituito_at'], 'idx_prestiti_attrezzatura_attivi');
            $table->index(['socio_id', 'dal'], 'idx_prestiti_socio_data');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('attrezzatura_id')->references('id')->on('attrezzature')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::create('progetti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome', 200);
            $table->text('descrizione')->nullable();
            $table->date('dal')->nullable();
            $table->date('al')->nullable();
            // Responsabile del progetto (utente admin, f.12)
            $table->uuid('responsabile_id')->nullable();
            // Valori ammessi: vedi Progetto::STATI ('bozza'|'attivo'|'concluso')
            $table->string('stato', 20)->default('bozza');
            $table->decimal('budget', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'stato'], 'idx_progetti_assoc_stato');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('responsabile_id')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('milestone_progetto', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('progetto_id');
            $table->string('titolo', 200);
            $table->date('scadenza')->nullable();
            // NULL = milestone non ancora completata (f.16)
            $table->dateTime('completata_at')->nullable();
            $table->unsignedSmallInteger('ordine')->default(0);
            $table->timestamps();

            $table->index(['progetto_id', 'ordine'], 'idx_milestone_progetto_ordine');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('progetto_id')->references('id')->on('progetti')->cascadeOnDelete();
        });

        Schema::create('turni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('titolo', 200);
            $table->date('data');
            $table->time('ora_inizio');
            $table->time('ora_fine');
            $table->unsignedSmallInteger('posti')->default(1);
            // Mansione richiesta opzionale (suggerimento volontari, f.3)
            $table->uuid('mansione_id')->nullable();
            // Collegamenti opzionali a evento, spazio e progetto (f.3, f.14)
            $table->uuid('evento_id')->nullable();
            $table->uuid('spazio_id')->nullable();
            $table->uuid('progetto_id')->nullable();
            $table->text('note')->nullable();
            // Valori ammessi: vedi Turno::STATI ('aperto'|'chiuso')
            $table->string('stato', 20)->default('aperto');
            // f.4: l'auto-candidatura del socio viene confermata subito
            $table->boolean('auto_conferma')->default(false);
            $table->timestamps();

            $table->index(['associazione_id', 'data'], 'idx_turni_assoc_data');
            $table->index(['associazione_id', 'stato', 'data'], 'idx_turni_assoc_stato_data');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('mansione_id')->references('id')->on('mansioni')->nullOnDelete();
            $table->foreign('evento_id')->references('id')->on('eventi')->nullOnDelete();
            $table->foreign('spazio_id')->references('id')->on('spazi')->nullOnDelete();
            $table->foreign('progetto_id')->references('id')->on('progetti')->nullOnDelete();
        });

        Schema::create('turni_assegnazioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('turno_id');
            $table->uuid('socio_id');
            // Valori ammessi: vedi TurnoAssegnazione::STATO_* ('proposto'|'confermato'|'rinunciato')
            $table->string('stato', 20)->default('proposto');
            // Ore effettive registrate al completamento del turno (registro f.5)
            $table->decimal('ore_effettive', 5, 2)->nullable();
            // Motivo della rinuncia comunicato dal socio (f.4)
            $table->string('motivo_rinuncia', 500)->nullable();
            $table->timestamps();

            // Un socio assegnato una sola volta per turno (f.3)
            $table->unique(['turno_id', 'socio_id'], 'uq_turni_assegnazioni_turno_socio');
            $table->index(['socio_id', 'stato'], 'idx_turni_assegnazioni_socio_stato');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('turno_id')->references('id')->on('turni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::table('eventi', function (Blueprint $table) {
            // f.11: spazio dell'evento (controllo conflitti con prenotazioni_spazi)
            $table->uuid('spazio_id')->nullable()->after('solo_soci');
            // f.14: progetto a cui l'evento contribuisce
            $table->uuid('progetto_id')->nullable()->after('spazio_id');
            // f.24: galleria foto visibile sulla pagina pubblica dell'evento
            $table->boolean('galleria_pubblica')->default(false)->after('progetto_id');

            $table->foreign('spazio_id')->references('id')->on('spazi')->nullOnDelete();
            $table->foreign('progetto_id')->references('id')->on('progetti')->nullOnDelete();
        });

        Schema::table('movimenti', function (Blueprint $table) {
            // f.13: rendiconto entrate/uscite per progetto
            $table->uuid('progetto_id')->nullable()->after('categoria_id')->index();

            $table->foreign('progetto_id')->references('id')->on('progetti')->nullOnDelete();
        });

        Schema::create('riunioni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('titolo', 200);
            $table->dateTime('data_ora');
            // Luogo libero e/o spazio censito (f.17)
            $table->string('luogo', 200)->nullable();
            $table->uuid('spazio_id')->nullable();
            // Ordine del giorno: [{punto, descrizione}, ...] (f.17)
            $table->json('odg')->nullable();
            // Valori ammessi: vedi Riunione::STATI ('pianificata'|'svolta'|'annullata')
            $table->string('stato', 20)->default('pianificata');
            // Verbale generato dalla bozza post-votazioni (f.18)
            $table->uuid('verbale_id')->nullable();
            // Quando l'admin ha inviato la convocazione email ai membri dell'organo
            $table->dateTime('convocazione_inviata_at')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'data_ora'], 'idx_riunioni_assoc_data');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('spazio_id')->references('id')->on('spazi')->nullOnDelete();
            $table->foreign('verbale_id')->references('id')->on('verbali')->nullOnDelete();
        });

        Schema::create('riunioni_partecipanti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('riunione_id');
            // Invitato: socio intestatario di carica (round 1) oppure utente admin
            $table->uuid('socio_id')->nullable();
            $table->uuid('utente_id')->nullable();
            // Snapshot del ruolo/carica al momento dell'invito (f.17)
            $table->string('carica_ruolo', 100)->nullable();
            // Valori ammessi: vedi RiunionePartecipante::STATI ('invitato'|'confermato'|'assente')
            $table->string('stato', 20)->default('invitato');
            $table->timestamps();

            // Un invito per socio e per utente in ogni riunione (NULL esclusi dall'unique)
            $table->unique(['riunione_id', 'socio_id'], 'uq_riunioni_partecipanti_socio');
            $table->unique(['riunione_id', 'utente_id'], 'uq_riunioni_partecipanti_utente');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('riunione_id')->references('id')->on('riunioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('utente_id')->references('id')->on('utenti')->cascadeOnDelete();
        });

        Schema::create('votazioni_riunione', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('riunione_id');
            // Indice (0-based) del punto dentro riunioni.odg (f.18)
            $table->unsignedSmallInteger('punto_indice');
            // Valori ammessi: vedi VotazioneRiunione::ESITI ('approvato'|'respinto'|'rinviato')
            $table->string('esito', 20);
            $table->unsignedSmallInteger('favorevoli')->default(0);
            $table->unsignedSmallInteger('contrari')->default(0);
            $table->unsignedSmallInteger('astenuti')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            // Un solo esito per punto OdG (f.18)
            $table->unique(['riunione_id', 'punto_indice'], 'uq_votazioni_riunione_punto');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('riunione_id')->references('id')->on('riunioni')->cascadeOnDelete();
        });

        Schema::create('adempimenti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('titolo', 200);
            $table->text('descrizione')->nullable();
            // Mese di scadenza ricorrente (1-12, f.20)
            $table->unsignedTinyInteger('mese_scadenza');
            $table->boolean('attivo')->default(true);
            $table->timestamps();

            $table->index(['associazione_id', 'mese_scadenza'], 'idx_adempimenti_assoc_mese');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });

        Schema::create('adempimenti_anni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('adempimento_id');
            $table->unsignedSmallInteger('anno');
            // NULL = non ancora completato per quell'anno (f.20)
            $table->dateTime('completato_at')->nullable();
            $table->uuid('completato_da')->nullable();
            $table->timestamps();

            // Una riga di checklist per adempimento e anno (f.20)
            $table->unique(['adempimento_id', 'anno'], 'uq_adempimenti_anni_anno');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('adempimento_id')->references('id')->on('adempimenti')->cascadeOnDelete();
            $table->foreign('completato_da')->references('id')->on('utenti')->nullOnDelete();
        });

        Schema::create('contatti_esterni', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            // Valori ammessi: vedi ContattoEsterno::TIPI
            // ('fornitore'|'ente'|'sponsor'|'stampa'|'altro')
            $table->string('tipo', 20)->default('altro');
            // Ragione sociale o nome della persona (f.21)
            $table->string('nome', 200);
            $table->string('referente', 150)->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->text('note')->nullable();
            // Etichette libere ["bando", "comune", ...] (f.21)
            $table->json('tag')->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'tipo'], 'idx_contatti_esterni_assoc_tipo');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });

        Schema::create('sponsor', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            // Scheda mini-CRM collegata opzionale (f.22)
            $table->uuid('contatto_esterno_id')->nullable();
            $table->string('nome', 200);
            $table->string('logo_url', 500)->nullable();
            // Valori ammessi: vedi Sponsor::LIVELLI ('oro'|'argento'|'bronzo'|'partner')
            $table->string('livello', 20)->default('partner');
            // Periodo di validità della sponsorizzazione (NULL = senza limite)
            $table->date('dal')->nullable();
            $table->date('al')->nullable();
            // Mostrato in "I nostri sostenitori" sulla home pubblica del mini-sito
            $table->boolean('visibile_sul_sito')->default(false);
            $table->string('sito_url', 500)->nullable();
            $table->timestamps();

            $table->index(['associazione_id', 'visibile_sul_sito'], 'idx_sponsor_assoc_visibile');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('contatto_esterno_id')->references('id')->on('contatti_esterni')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsor');
        Schema::dropIfExists('contatti_esterni');
        Schema::dropIfExists('adempimenti_anni');
        Schema::dropIfExists('adempimenti');
        Schema::dropIfExists('votazioni_riunione');
        Schema::dropIfExists('riunioni_partecipanti');
        Schema::dropIfExists('riunioni');

        Schema::table('movimenti', function (Blueprint $table) {
            $table->dropForeign(['progetto_id']);
            $table->dropColumn('progetto_id');
        });

        Schema::table('eventi', function (Blueprint $table) {
            $table->dropForeign(['spazio_id']);
            $table->dropForeign(['progetto_id']);
            $table->dropColumn(['spazio_id', 'progetto_id', 'galleria_pubblica']);
        });

        Schema::dropIfExists('turni_assegnazioni');
        Schema::dropIfExists('turni');
        Schema::dropIfExists('milestone_progetto');
        Schema::dropIfExists('progetti');
        Schema::dropIfExists('prestiti_attrezzature');
        Schema::dropIfExists('attrezzature');
        Schema::dropIfExists('prenotazioni_spazi');
        Schema::dropIfExists('spazi');
        Schema::dropIfExists('socio_mansioni');
        Schema::dropIfExists('mansioni');

        Schema::table('richieste_iscrizione', function (Blueprint $table) {
            $table->dropColumn('referral_codice');
        });

        Schema::table('soci', function (Blueprint $table) {
            $table->dropUnique('uq_soci_codice_referral');
            $table->dropColumn(['disponibilita', 'volontario_attivo', 'codice_referral']);
        });
    }
};
