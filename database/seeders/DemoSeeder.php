<?php

namespace Database\Seeders;

use App\Models\Associazione;
use App\Models\CategoriaSocio;
use App\Models\Evento;
use App\Models\Quota;
use App\Models\Sede;
use App\Models\Socio;
use App\Models\Tessera;
use App\Models\TipoSocio;
use Illuminate\Database\Seeder;

/**
 * Dati dimostrativi per l'installer web: SOLO contenuti (associazione, soci,
 * tessere, quote, un evento), NESSUN account utente e nessuna factory
 * (Faker è una dipendenza dev, assente con composer install --no-dev).
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $associazione = Associazione::create([
            'nome' => 'Associazione Demo',
            'email' => 'info@associazione-demo.test',
            'citta' => 'Milano',
            'provincia' => 'MI',
            'cap' => '20121',
            'indirizzo' => 'Via Esempio 1',
            'attiva' => true,
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

        Evento::create([
            'associazione_id' => $associazione->id,
            'titolo' => 'Assemblea annuale',
            'descrizione' => 'Assemblea ordinaria dei soci',
            'data_evento' => now()->addMonth()->toDateString(),
            'luogo' => 'Sede Centrale',
        ]);
    }
}
