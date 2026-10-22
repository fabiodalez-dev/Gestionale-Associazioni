<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Adempimento;
use App\Models\Annuncio;
use App\Models\Associazione;
use App\Models\AttivitaAdmin;
use App\Models\Attrezzatura;
use App\Models\CampoPersonalizzato;
use App\Models\Carica;
use App\Models\CategoriaMovimento;
use App\Models\CategoriaSocio;
use App\Models\Cessazione;
use App\Models\ContattoEsterno;
use App\Models\Donazione;
use App\Models\Evento;
use App\Models\Mansione;
use App\Models\MilestoneProgetto;
use App\Models\Movimento;
use App\Models\Nucleo;
use App\Models\Prenotazione;
use App\Models\PrenotazioneSpazio;
use App\Models\PrestitoAttrezzatura;
use App\Models\Progetto;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\RichiestaIscrizione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Sondaggio;
use App\Models\Spazio;
use App\Models\Sponsor;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Models\TipoSocio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\ValoreCampoPersonalizzato;
use App\Models\Verbale;
use App\Models\VotazioneRiunione;
use App\Services\Economia\PrimaNotaService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Dati dimostrativi per l'installer web: SOLO contenuti (associazione, soci,
 * tessere, quote, eventi pubblicati, account demo con prenotazione, form di
 * iscrizione configurato e una richiesta di iscrizione pendente), NESSUN
 * account utente amministratore e nessuna factory (Faker è una dipendenza
 * dev, assente con composer install --no-dev).
 *
 * Il frontend pubblico è attivo su /a/associazione-demo; l'account demo
 * (demo@account-demo.test / demo1234) è verificato e ha una prenotazione
 * confermata sull'evento gratuito. Il secondo account
 * (giulia@account-demo.test / demo1234) ha una richiesta di iscrizione
 * socio in attesa, visibile nella pagina admin "Richieste iscrizione".
 * La "Visita guidata in cantina" è l'evento con approvazione richiesta.
 *
 * Vita sociale (PIANO-VITA-SOCIALE): due cariche negli organi sociali
 * (una in scadenza, alert dashboard), un ex socio con cessazione formale
 * storica (visibile nel libro soci), un verbale numerato 1/{anno}
 * collegato all'assemblea, una serie di eventi ricorrenti solo-soci e
 * una ricevuta numerata 1/{anno} sulla quota pagata.
 *
 * Gestione 2 (PIANO-GESTIONE-2): un annuncio pubblicato in evidenza (f.7),
 * un sondaggio aperto (f.11), prima nota con categorie seedate e tre
 * movimenti — incasso quota e donazione generati dagli hook automatici
 * idempotenti di PrimaNotaService + un'uscita manuale (f.13), una
 * donazione pagata (f.15), il nucleo familiare "Verdi-Bianchi" (f.20)
 * e un'attività admin aperta collegata all'assemblea (f.4).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $associazione = Associazione::create([
            'nome' => 'Associazione Demo',
            'slug' => 'associazione-demo',
            'email' => 'info@associazione-demo.test',
            'citta' => 'Milano',
            'provincia' => 'MI',
            'cap' => '20121',
            'indirizzo' => 'Via Esempio 1',
            'attiva' => true,
            'frontend_pubblico_attivo' => true,
            'descrizione_pubblica' => 'Associazione dimostrativa: scopri i nostri eventi e prenota il tuo posto online.',
        ]);

        $sede = Sede::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Sede Centrale',
            'citta' => 'Milano',
            'provincia' => 'MI',
        ]);

        $tipoSocio = TipoSocio::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Ordinario',
            'descrizione' => 'Socio ordinario',
        ]);

        CategoriaSocio::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Fondatori',
            'descrizione' => 'Soci fondatori',
        ]);

        // Form di iscrizione pubblico /a/associazione-demo/iscriviti
        // (PIANO-ISCRIZIONI §Form personalizzabile): modalità preiscrizione
        // (l'admin approva da "Richieste iscrizione"), campi standard
        // configurati + un campo personalizzato riusato da config_campi
        $campoProfessione = CampoPersonalizzato::create([
            'associazione_id' => $associazione->id,
            'nome_campo' => 'Professione',
            'tipo_campo' => 'text',
            'descrizione' => 'La tua professione attuale',
            'obbligatorio' => false,
            'ordine' => 1,
        ]);

        $associazione->update([
            'iscrizioni_socio_aperte' => true,
            'modalita_iscrizione' => Associazione::MODALITA_PREISCRIZIONE,
            'iscrizione_form_config' => [
                'intro_html' => '<p>Compila il modulo per richiedere l\'iscrizione: '
                    .'la segreteria valuterà la tua richiesta entro pochi giorni.</p>',
                'campi_standard' => [
                    ['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => true],
                    ['campo' => 'codice_fiscale', 'visibile' => true, 'obbligatorio' => false],
                    ['campo' => 'data_nascita', 'visibile' => true, 'obbligatorio' => true],
                    ['campo' => 'indirizzo', 'visibile' => false, 'obbligatorio' => false],
                    ['campo' => 'citta', 'visibile' => true, 'obbligatorio' => false],
                    ['campo' => 'provincia', 'visibile' => false, 'obbligatorio' => false],
                    ['campo' => 'cap', 'visibile' => false, 'obbligatorio' => false],
                ],
                'campi_personalizzati' => [$campoProfessione->id],
            ],
        ]);

        $anno = (int) now()->format('Y');
        $soci = [
            ['nome' => 'Anna', 'cognome' => 'Verdi', 'email' => 'anna.verdi@demo.test'],
            ['nome' => 'Luca', 'cognome' => 'Bianchi', 'email' => 'luca.bianchi@demo.test'],
            ['nome' => 'Sara', 'cognome' => 'Russo', 'email' => 'sara.russo@demo.test'],
        ];

        /** @var array<int, Socio> $sociModels */
        $sociModels = [];
        $quotaPagata = null;

        foreach ($soci as $i => $dati) {
            $socio = Socio::create([
                'associazione_id' => $associazione->id,
                'sede_id' => $sede->id,
                'tipo_socio_id' => $tipoSocio->id,
                'numero_socio' => str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'nome' => $dati['nome'],
                'cognome' => $dati['cognome'],
                'email' => $dati['email'],
                'data_nascita' => now()->subYears(30 + $i)->toDateString(),
                'data_iscrizione' => now()->subMonths($i + 1)->toDateString(),
                'stato' => 'Attivo',
                'privacy_consenso' => true,
            ]);

            Tessera::create([
                'socio_id' => $socio->id,
                'associazione_id' => $associazione->id,
                'numero_tessera' => sprintf('%d%04d', $anno, $i + 1),
                'anno_validita' => $anno,
                'data_emissione' => now()->subMonths($i + 1)->toDateString(),
                'data_scadenza' => "{$anno}-12-31",
                'tipo_scadenza' => 'solare',
                'stato' => 'Attiva',
            ]);

            $quota = Quota::create([
                'socio_id' => $socio->id,
                'associazione_id' => $associazione->id,
                'anno' => $anno,
                'importo' => 25.00,
                'tipo' => 'Quota Associativa',
                'data_scadenza' => "{$anno}-12-31",
                'stato' => $i === 0 ? 'Pagata' : 'Da Pagare',
                'data_pagamento' => $i === 0 ? now()->subMonths(1)->toDateString() : null,
            ]);

            $sociModels[] = $socio;

            if ($i === 0) {
                $quotaPagata = $quota;
            }
        }

        // Evento pubblicato GRATUITO con capienza: prenotabile dal
        // frontend pubblico /a/associazione-demo
        $eventoGratuito = Evento::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Assemblea annuale',
            'descrizione' => 'Assemblea ordinaria dei soci, aperta anche ai simpatizzanti.',
            'data_evento' => now()->addMonth(),
            'luogo' => 'Sede Centrale',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 50,
            'prezzo' => 0,
            'max_posti_per_prenotazione' => 4,
            'lista_attesa_attiva' => true,
            'annullamento_entro_ore' => 24,
        ]);

        // Evento pubblicato A PAGAMENTO (checkout Stripe/PayPal se i
        // gateway del tenant sono configurati)
        Evento::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Cena sociale di primavera',
            'descrizione' => 'Cena conviviale con menù fisso, bevande incluse.',
            'data_evento' => now()->addWeeks(6),
            'luogo' => 'Ristorante Il Cortile, Milano',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 30,
            'prezzo' => 25.00,
            'max_posti_per_prenotazione' => 4,
            'lista_attesa_attiva' => true,
            'annullamento_entro_ore' => 48,
        ]);

        // Evento pubblicato CON APPROVAZIONE RICHIESTA: le prenotazioni
        // nascono "in approvazione" (posti riservati) e l'admin le gestisce
        // dal tab Prenotazioni dell'evento (PIANO-ISCRIZIONI §Preiscrizione)
        Evento::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Visita guidata in cantina',
            'descrizione' => 'Visita con degustazione, posti limitati: ogni richiesta viene approvata dalla segreteria.',
            'data_evento' => now()->addWeeks(3),
            'luogo' => 'Cantina Sociale, Pavia',
            'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
            'capienza' => 15,
            'prezzo' => 0,
            'max_posti_per_prenotazione' => 2,
            'lista_attesa_attiva' => true,
            'annullamento_entro_ore' => 24,
            'iscrizioni_aperte' => true,
            'richiede_approvazione' => true,
        ]);

        // Account demo del frontend pubblico: email verificata,
        // password "demo1234" (hash dal cast 'hashed' del modello)
        $account = Account::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Mario',
            'cognome' => 'Rossi',
            'email' => 'demo@account-demo.test',
            'password' => 'demo1234',
            'email_verified_at' => now(),
            'telefono' => '3331234567',
            'attivo' => true,
            'preferenze' => ['promemoria_eventi' => true],
            'privacy_accettata_at' => now(),
        ]);

        // Prenotazione CONFERMATA dell'account demo sull'evento gratuito
        // (biglietto con QR visibile nell'area riservata)
        Prenotazione::create([
            'associazione_id' => $associazione->id,
            'evento_id' => $eventoGratuito->id,
            'account_id' => $account->id,
            'codice' => Prenotazione::generaCodice(),
            'qr_token' => Prenotazione::generaQrToken(),
            'stato' => Prenotazione::STATO_CONFERMATA,
            'posti' => 2,
            'prezzo_unitario' => 0,
            'importo_totale' => 0,
        ]);

        // Secondo account con RICHIESTA DI ISCRIZIONE PENDENTE: il flusso
        // del form pubblico già percorso (socio preiscritto "In Attesa" +
        // snapshot dati_form), da approvare/rifiutare nella pagina admin
        // "Richieste iscrizione" (specchio di FormIscrizioneService::salva)
        $accountRichiedente = Account::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Giulia',
            'cognome' => 'Neri',
            'email' => 'giulia@account-demo.test',
            'password' => 'demo1234',
            'email_verified_at' => now(),
            'telefono' => '3387654321',
            'attivo' => true,
            'preferenze' => ['promemoria_eventi' => true],
            'privacy_accettata_at' => now(),
        ]);

        $preiscritta = Socio::create([
            'associazione_id' => $associazione->id,
            'numero_socio' => '004',
            'nome' => $accountRichiedente->nome,
            'cognome' => $accountRichiedente->cognome,
            'email' => $accountRichiedente->email,
            'telefono' => '3387654321',
            'data_nascita' => '1992-03-15',
            'citta' => 'Milano',
            'data_iscrizione' => now()->toDateString(),
            'stato' => Socio::STATO_IN_ATTESA,
            'privacy_consenso' => true,
        ]);

        ValoreCampoPersonalizzato::create([
            'socio_id' => $preiscritta->id,
            'campo_id' => $campoProfessione->id,
            'valore' => 'Grafica freelance',
        ]);

        RichiestaIscrizione::create([
            'associazione_id' => $associazione->id,
            'account_id' => $accountRichiedente->id,
            'socio_id' => $preiscritta->id,
            'stato' => RichiestaIscrizione::STATO_IN_ATTESA,
            'dati_form' => [
                'telefono' => '3387654321',
                'data_nascita' => '1992-03-15',
                'citta' => 'Milano',
                'custom_field' => [$campoProfessione->id => 'Grafica freelance'],
            ],
        ]);

        $accountRichiedente->forceFill(['richiesta_socio_at' => now()])->save();

        /*
        |------------------------------------------------------------------
        | Vita sociale (PIANO-VITA-SOCIALE) — dati dimostrativi
        |------------------------------------------------------------------
        */

        // f.16 Organi sociali: una carica in scadenza entro 90 giorni
        // (alimenta l'alert "mandati in scadenza" in dashboard) e una
        // in corso senza fine mandato
        Carica::create([
            'associazione_id' => $associazione->id,
            'socio_id' => $sociModels[0]->id,
            'ruolo' => 'Presidente',
            'inizio' => now()->subYears(3)->addDays(60)->toDateString(),
            'fine' => now()->addDays(60)->toDateString(),
            'note' => 'Mandato triennale in scadenza: rinnovo alla prossima assemblea.',
        ]);

        Carica::create([
            'associazione_id' => $associazione->id,
            'socio_id' => $sociModels[1]->id,
            'ruolo' => 'Segretario',
            'inizio' => now()->subYear()->toDateString(),
            'fine' => null,
        ]);

        // f.15 Cessazione storica: ex socio dimissionario dell'anno scorso,
        // con tessera annullata e riga nello storico attività — compare nel
        // libro soci (f.13) con data e motivo di cessazione
        $exSocio = Socio::create([
            'associazione_id' => $associazione->id,
            'sede_id' => $sede->id,
            'tipo_socio_id' => $tipoSocio->id,
            'numero_socio' => '005',
            'nome' => 'Paolo',
            'cognome' => 'Gallo',
            'email' => 'paolo.gallo@demo.test',
            'data_nascita' => now()->subYears(45)->toDateString(),
            'data_iscrizione' => now()->subYears(3)->toDateString(),
            'stato' => Socio::STATO_RADIATO,
            'privacy_consenso' => true,
        ]);

        Tessera::create([
            'socio_id' => $exSocio->id,
            'associazione_id' => $associazione->id,
            'numero_tessera' => sprintf('%d%04d', $anno - 1, 5),
            'anno_validita' => $anno - 1,
            'data_emissione' => now()->subYears(1)->startOfYear()->toDateString(),
            'data_scadenza' => ($anno - 1).'-12-31',
            'tipo_scadenza' => 'solare',
            'stato' => Tessera::STATO_ANNULLATA,
        ]);

        $cessazione = Cessazione::create([
            'associazione_id' => $associazione->id,
            'socio_id' => $exSocio->id,
            'tipo' => Cessazione::TIPO_DIMISSIONI,
            'data' => now()->subMonths(4)->toDateString(),
            'motivo' => 'Dimissioni volontarie per trasferimento all\'estero.',
            'delibera_numero' => '7/'.($anno - 1),
            'delibera_data' => now()->subMonths(4)->toDateString(),
        ]);

        // Specchio di CessazioneService::registra (storico fail-safe)
        StoricoAttivitaSocio::create([
            'associazione_id' => $associazione->id,
            'socio_id' => $exSocio->id,
            'tipo_attivita' => 'Cessazione',
            'descrizione' => sprintf(
                'Cessazione per %s registrata con decorrenza %s (delibera n. %s). Stato aggiornato a "%s", tessere attive annullate.',
                Cessazione::TIPO_DIMISSIONI,
                now()->subMonths(4)->format('d/m/Y'),
                $cessazione->delibera_numero,
                Socio::STATO_RADIATO
            ),
            'dettagli_json' => [
                'cessazione_id' => $cessazione->id,
                'tipo' => Cessazione::TIPO_DIMISSIONI,
                'motivo' => $cessazione->motivo,
            ],
            'data_attivita' => now()->subMonths(4),
        ]);

        // f.17 Verbale numerato (1/{anno}) collegato all'assemblea annuale,
        // con riferimento alla delibera della cessazione qui sopra
        Verbale::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Verbale del Consiglio Direttivo — programmazione attività',
            'numero' => 1,
            'anno' => $anno,
            'data_riunione' => now()->subMonths(2)->toDateString(),
            'tipo_riunione' => 'Consiglio Direttivo',
            'presenti' => 'Anna Verdi (Presidente), Luca Bianchi (Segretario), Sara Russo',
            'ordine_del_giorno' => "1. Approvazione programma eventi\n2. Convocazione assemblea annuale\n3. Varie ed eventuali",
            'deliberazioni' => 'Delibera n. 1/'.$anno.': approvato il calendario eventi e convocata l\'assemblea annuale dei soci.',
            'approvato' => false,
            'evento_id' => $eventoGratuito->id,
        ]);

        // f.8 Evento ricorrente: serie settimanale di 3 occorrenze
        // (serie_id condiviso) riservata ai soci (f.10: badge "Riservato
        // ai soci" nel mini-sito, prenotabile solo da account-socio attivi)
        $serieId = (string) Str::uuid();

        foreach ([1, 2, 3] as $settimana) {
            Evento::create([
                'associazione_id' => $associazione->id,
                'titolo' => 'Serata giochi al circolo',
                'descrizione' => 'Appuntamento settimanale di socialità: giochi da tavolo e carte in sede.',
                'data_evento' => now()->addWeeks($settimana)->setTime(21, 0),
                'luogo' => 'Sede Centrale',
                'stato_pubblicazione' => Evento::STATO_PUBBLICATO,
                'capienza' => 20,
                'prezzo' => 0,
                'max_posti_per_prenotazione' => 2,
                'lista_attesa_attiva' => false,
                'annullamento_entro_ore' => 12,
                'serie_id' => $serieId,
                'solo_soci' => true,
            ]);
        }

        // f.21 Ricevuta numerata (1/{anno}) della quota già pagata di Anna
        // Verdi (prima socia dell'elenco), scaricabile da admin (Quote) e
        // dall'area soci
        Ricevuta::create([
            'associazione_id' => $associazione->id,
            'quota_id' => $quotaPagata->id,
            'numero' => 1,
            'anno' => $anno,
            'importo' => $quotaPagata->importo,
            'emessa_at' => now()->subMonths(1),
        ]);

        /*
        |------------------------------------------------------------------
        | Gestione 2 (PIANO-GESTIONE-2) — dati dimostrativi
        |------------------------------------------------------------------
        */

        // f.7 Annuncio pubblicato in evidenza: visibile nella sezione
        // "Novità" del mini-sito pubblico, nella bacheca dell'area soci
        // e selezionabile per le newsletter (f.8)
        Annuncio::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Aperte le iscrizioni alla stagione '.$anno,
            'corpo_html' => '<p>Da oggi è possibile rinnovare la tessera e iscriversi '
                .'ai nuovi corsi della stagione. Passa in segreteria o usa il '
                .'modulo online: ti aspettiamo!</p>',
            'pubblicato_dal' => now()->subDays(7),
            'pubblicato_al' => null,
            'in_evidenza' => true,
            'solo_soci' => false,
        ]);

        // f.11 Sondaggio aperto: votabile dall'area soci e dall'area
        // account del mini-sito (un voto per votante)
        Sondaggio::create([
            'associazione_id' => $associazione->id,
            'domanda' => 'Quale attività vorresti per la prossima stagione?',
            'opzioni' => ['Corso di cucina', 'Gite culturali', 'Tornei di giochi da tavolo'],
            'aperto_dal' => now()->subDays(3),
            'aperto_al' => now()->addMonth(),
            'solo_soci' => false,
        ]);

        // f.15 Donazione online già pagata: compare nell'elenco admin
        // "Donazioni" e genera il movimento automatico in prima nota
        $donazione = Donazione::create([
            'associazione_id' => $associazione->id,
            'donatore_nome' => 'Carla Fontana',
            'donatore_email' => 'carla.fontana@demo.test',
            'importo' => 50.00,
            'anonimo' => false,
            'stato' => Donazione::STATO_PAGATA,
            'pagata_at' => now()->subDays(10),
        ]);

        // f.13 Prima nota: categorie di default del tenant + tre movimenti.
        // Gli incassi (quota pagata, donazione) passano dagli stessi hook
        // automatici idempotenti usati a runtime; l'uscita è manuale.
        $primaNota = app(PrimaNotaService::class);
        $primaNota->seedCategorie($associazione->id);

        $primaNota->registraIncassoQuotaBestEffort($quotaPagata->id);
        $primaNota->registraDonazioneBestEffort($donazione);

        Movimento::create([
            'associazione_id' => $associazione->id,
            'tipo' => Movimento::TIPO_USCITA,
            'importo' => 180.00,
            'data' => now()->subDays(15)->toDateString(),
            'categoria_id' => $primaNota->categoria(
                $associazione->id,
                'Utenze e affitti',
                CategoriaMovimento::TIPO_USCITA
            )?->id,
            'descrizione' => 'Affitto sede — mensilità corrente',
            'metodo' => 'bonifico',
        ]);

        // f.20 Nucleo familiare: Anna Verdi capofamiglia, Luca Bianchi
        // membro — visibile nella scheda socio e nella vista "per nucleo"
        $nucleo = Nucleo::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Famiglia Verdi-Bianchi',
            'capofamiglia_id' => $sociModels[0]->id,
        ]);

        $sociModels[0]->update(['nucleo_id' => $nucleo->id]);
        $sociModels[1]->update(['nucleo_id' => $nucleo->id]);

        // f.4 Attività admin aperta collegata all'assemblea annuale:
        // compare nella pagina "Attività" e nel widget della dashboard
        // (assegnatario assente: il seeder non crea utenti amministratori)
        AttivitaAdmin::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Preparare i materiali per l\'assemblea annuale',
            'descrizione' => 'Stampare il foglio presenze, predisporre il verbale e verificare la capienza della sala.',
            'scadenza' => now()->addDays(7)->toDateString(),
            'notabile_type' => $eventoGratuito->getMorphClass(),
            'notabile_id' => $eventoGratuito->id,
            'stato' => AttivitaAdmin::STATO_APERTA,
        ]);

        /*
        |------------------------------------------------------------------
        | Organizzazione 3 (PIANO-ORGANIZZAZIONE-3) — dati dimostrativi
        |------------------------------------------------------------------
        */

        // f.2 Mansione/competenza del tenant, assegnata ad Anna Verdi (pivot
        // socio_mansioni): alimenta i suggerimenti volontari per i turni e il
        // filtro "per mansione" nel libro soci
        $mansione = Mansione::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Accoglienza',
            'descrizione' => 'Reception ospiti, gestione iscrizioni in loco e info point.',
        ]);

        $sociModels[0]->mansioni()->attach($mansione->id);

        // f.7 Spazio interno prenotabile (sala riunioni in sede)
        $spazio = Spazio::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Sala Riunioni',
            'descrizione' => 'Sala polivalente al primo piano della sede.',
            'capienza' => 25,
            'sede_id' => $sede->id,
            'attivo' => true,
        ]);

        // f.8 Prenotazione interna dello spazio (domani 10:00–12:00): la vista
        // calendario settimanale la mostra e il rilevatore di conflitti la usa
        // come riferimento per gli overlap
        PrenotazioneSpazio::create([
            'associazione_id' => $associazione->id,
            'spazio_id' => $spazio->id,
            'titolo' => 'Prova del coro',
            'dal' => now()->addDay()->setTime(10, 0),
            'al' => now()->addDay()->setTime(12, 0),
            'note' => 'Prenotazione dimostrativa della sala riunioni.',
        ]);

        // f.3 Turno di volontariato (domani mattina) con mansione e spazio
        // collegati; f.4-5 Anna Verdi assegnata e confermata (compare nei
        // confermati per il promemoria turni:promemoria)
        $turno = Turno::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Accoglienza assemblea',
            'data' => now()->addDay()->toDateString(),
            'ora_inizio' => '09:00',
            'ora_fine' => '13:00',
            'posti' => 3,
            'mansione_id' => $mansione->id,
            'evento_id' => $eventoGratuito->id,
            'spazio_id' => $spazio->id,
            'note' => 'Accoglienza soci e ospiti all\'ingresso.',
            'stato' => Turno::STATO_APERTO,
            'auto_conferma' => false,
        ]);

        TurnoAssegnazione::create([
            'associazione_id' => $associazione->id,
            'turno_id' => $turno->id,
            'socio_id' => $sociModels[0]->id,
            'stato' => TurnoAssegnazione::STATO_CONFERMATO,
        ]);

        // f.9 Attrezzatura in inventario (proiettore) e f.10 un prestito attivo
        // a Luca Bianchi non ancora restituito: riduce la disponibilità e
        // alimenta lo storico prestiti nella scheda socio
        $attrezzatura = Attrezzatura::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Proiettore Full HD',
            'descrizione' => 'Videoproiettore con telecomando e cavo HDMI.',
            'quantita' => 2,
            'valore_unitario' => 320.00,
            'stato' => Attrezzatura::STATO_BUONO,
            'spazio_id' => $spazio->id,
        ]);

        PrestitoAttrezzatura::create([
            'associazione_id' => $associazione->id,
            'attrezzatura_id' => $attrezzatura->id,
            'socio_id' => $sociModels[1]->id,
            'quantita' => 1,
            'dal' => now()->subDays(3)->toDateString(),
            'restituzione_prevista' => now()->addWeek()->toDateString(),
            'restituito_at' => null,
            'note' => 'Prestito per proiezione serata informativa.',
        ]);

        // f.12 Progetto attivo con budget (responsabile assente: il seeder non
        // crea utenti amministratori), f.16 milestone (una completata, una in
        // scadenza) e f.13 un movimento di prima nota imputato al progetto
        $progetto = Progetto::create([
            'associazione_id' => $associazione->id,
            'nome' => 'Festa di quartiere '.$anno,
            'descrizione' => 'Organizzazione della festa annuale aperta alla cittadinanza.',
            'dal' => now()->startOfYear()->toDateString(),
            'al' => now()->endOfYear()->toDateString(),
            'responsabile_id' => null,
            'stato' => Progetto::STATO_ATTIVO,
            'budget' => 3000.00,
        ]);

        MilestoneProgetto::create([
            'associazione_id' => $associazione->id,
            'progetto_id' => $progetto->id,
            'titolo' => 'Richiesta autorizzazioni al Comune',
            'scadenza' => now()->subMonth()->toDateString(),
            'completata_at' => now()->subWeeks(3),
            'ordine' => 1,
        ]);

        MilestoneProgetto::create([
            'associazione_id' => $associazione->id,
            'progetto_id' => $progetto->id,
            'titolo' => 'Allestimento e logistica',
            'scadenza' => now()->addMonth()->toDateString(),
            'completata_at' => null,
            'ordine' => 2,
        ]);

        Movimento::create([
            'associazione_id' => $associazione->id,
            'tipo' => Movimento::TIPO_USCITA,
            'importo' => 250.00,
            'data' => now()->subDays(5)->toDateString(),
            'categoria_id' => $primaNota->categoria(
                $associazione->id,
                'Utenze e affitti',
                CategoriaMovimento::TIPO_USCITA
            )?->id,
            'progetto_id' => $progetto->id,
            'descrizione' => 'Noleggio service audio per la festa di quartiere',
            'metodo' => 'bonifico',
        ]);

        // f.17 Riunione del direttivo già SVOLTA (abilita votazioni e bozza
        // verbale), con OdG strutturato; un partecipante confermato (Anna
        // Verdi, intestataria di carica) e f.18 una votazione approvata sul
        // primo punto dell'OdG (confluisce nell'Archivio decisioni, f.19)
        $riunione = Riunione::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Consiglio Direttivo di programmazione',
            'data_ora' => now()->subDays(2)->setTime(21, 0),
            'luogo' => 'Sede Centrale',
            'spazio_id' => $spazio->id,
            'odg' => [
                ['punto' => 'Approvazione calendario eventi', 'descrizione' => 'Voto sul programma della stagione.'],
                ['punto' => 'Varie ed eventuali', 'descrizione' => ''],
            ],
            'stato' => Riunione::STATO_SVOLTA,
        ]);

        RiunionePartecipante::create([
            'associazione_id' => $associazione->id,
            'riunione_id' => $riunione->id,
            'socio_id' => $sociModels[0]->id,
            'carica_ruolo' => 'Presidente',
            'stato' => RiunionePartecipante::STATO_CONFERMATO,
        ]);

        VotazioneRiunione::create([
            'associazione_id' => $associazione->id,
            'riunione_id' => $riunione->id,
            'punto_indice' => 0,
            'esito' => VotazioneRiunione::ESITO_APPROVATO,
            'favorevoli' => 5,
            'contrari' => 0,
            'astenuti' => 1,
            'note' => 'Calendario eventi approvato a larga maggioranza.',
        ]);

        // f.20 Adempimenti ricorrenti del tenant: due voci con mese di scadenza
        // diverso, alimentano la nuova sezione dello Scadenzario e il
        // promemoria mensile adempimenti:promemoria
        Adempimento::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Rinnovo assicurazione volontari',
            'descrizione' => 'Rinnovo della polizza infortuni e RC per i volontari.',
            'mese_scadenza' => 1,
            'attivo' => true,
        ]);

        Adempimento::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Approvazione bilancio annuale',
            'descrizione' => 'Predisposizione e approvazione del bilancio consuntivo in assemblea.',
            'mese_scadenza' => 4,
            'attivo' => true,
        ]);

        // f.21 Contatto esterno (mini-CRM): un fornitore con tag e referente,
        // ricercabile e filtrabile per tipo nella pagina Contatti
        $contattoSponsor = ContattoEsterno::create([
            'associazione_id' => $associazione->id,
            'tipo' => ContattoEsterno::TIPO_SPONSOR,
            'nome' => 'Caffè del Borgo S.r.l.',
            'referente' => 'Marco Bianchi',
            'email' => 'marco@caffedelborgo.test',
            'telefono' => '0212345678',
            'note' => 'Sponsor storico della festa di quartiere.',
            'tag' => ['ristorazione', 'sponsor-locale'],
        ]);

        // f.22 Sponsor visibile sul mini-sito (livello oro, periodo in corso):
        // compare nella sezione "I nostri sostenitori" della home pubblica,
        // collegato al contatto esterno qui sopra
        Sponsor::create([
            'associazione_id' => $associazione->id,
            'contatto_esterno_id' => $contattoSponsor->id,
            'nome' => 'Caffè del Borgo',
            'logo_url' => null,
            'livello' => Sponsor::LIVELLO_ORO,
            'dal' => now()->startOfYear()->toDateString(),
            'al' => now()->endOfYear()->toDateString(),
            'visibile_sul_sito' => true,
            'sito_url' => 'https://caffedelborgo.test',
        ]);
    }
}
