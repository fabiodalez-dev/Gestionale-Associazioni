<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CategoriaSocio;
use App\Models\Evento;
use App\Models\Prenotazione;
use App\Models\Quota;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TipoSocio;
use Illuminate\Database\Seeder;

/**
 * Dati dimostrativi per l'installer web: SOLO contenuti (associazione, soci,
 * tessere, quote, eventi pubblicati, un account demo con prenotazione),
 * NESSUN account utente amministratore e nessuna factory (Faker è una
 * dipendenza dev, assente con composer install --no-dev).
 *
 * Il frontend pubblico è attivo su /a/associazione-demo; l'account demo
 * (demo@account-demo.test / demo1234) è verificato e ha una prenotazione
 * confermata sull'evento gratuito.
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

        $anno = (int) now()->format('Y');
        $soci = [
            ['nome' => 'Anna', 'cognome' => 'Verdi', 'email' => 'anna.verdi@demo.test'],
            ['nome' => 'Luca', 'cognome' => 'Bianchi', 'email' => 'luca.bianchi@demo.test'],
            ['nome' => 'Sara', 'cognome' => 'Russo', 'email' => 'sara.russo@demo.test'],
        ];

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

            Quota::create([
                'socio_id' => $socio->id,
                'associazione_id' => $associazione->id,
                'anno' => $anno,
                'importo' => 25.00,
                'tipo' => 'Quota Associativa',
                'data_scadenza' => "{$anno}-12-31",
                'stato' => $i === 0 ? 'Pagata' : 'Da Pagare',
                'data_pagamento' => $i === 0 ? now()->subMonths(1)->toDateString() : null,
            ]);
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
    }
}
