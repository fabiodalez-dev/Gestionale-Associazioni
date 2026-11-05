<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ricevute (issue #20): istantanea dell'intestatario e dei dati del
 * pagamento salvata all'emissione. Prima il PDF leggeva nome, numero socio,
 * tipo quota e metodo dai dati correnti di socio e quota, quindi una
 * correzione successiva cambiava una ricevuta già emessa.
 *
 * Colonne tutte nullable e nessun backfill: le ricevute emesse prima di
 * questa migration non hanno l'istantanea e il PDF usa i dati correnti
 * (stesso comportamento di prima).
 */
return new class extends Migration
{
    /** @var list<string> */
    private const COLONNE = [
        'intestatario_nome',
        'intestatario_cognome',
        'intestatario_codice_fiscale',
        'numero_socio',
        'tipo_quota',
        'metodo_pagamento',
        'data_pagamento',
        'riferimento_transazione',
    ];

    public function up(): void
    {
        Schema::table('ricevute', function (Blueprint $table) {
            // Stesse lunghezze delle colonne di origine (soci, quote)
            $table->string('intestatario_nome', 100)->nullable()->after('emessa_at');
            $table->string('intestatario_cognome', 100)->nullable()->after('intestatario_nome');
            $table->string('intestatario_codice_fiscale', 16)->nullable()->after('intestatario_cognome');
            $table->string('numero_socio', 50)->nullable()->after('intestatario_codice_fiscale');
            $table->string('tipo_quota', 100)->nullable()->after('numero_socio');
            $table->string('metodo_pagamento', 50)->nullable()->after('tipo_quota');
            $table->date('data_pagamento')->nullable()->after('metodo_pagamento');
            $table->string('riferimento_transazione')->nullable()->after('data_pagamento');
        });
    }

    public function down(): void
    {
        Schema::table('ricevute', function (Blueprint $table) {
            $table->dropColumn(self::COLONNE);
        });
    }
};
