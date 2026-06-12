<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\Carica;
use App\Models\CategoriaSocio;
use App\Models\Cessazione;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Ricevuta;
use App\Models\RichiestaIscrizione;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Tessera;
use App\Models\TipoSocio;
use App\Models\ValoreCampoPersonalizzato;
use App\Models\Verbale;
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
        // Verdi, scaricabile da admin (Quote) e dall'area soci
        if ($quotaPagata !== null) {
            Ricevuta::create([
                'associazione_id' => $associazione->id,
                'quota_id' => $quotaPagata->id,
                'numero' => 1,
                'anno' => $anno,
                'importo' => $quotaPagata->importo,
                'emessa_at' => now()->subMonths(1),
            ]);
        }
    }
}
