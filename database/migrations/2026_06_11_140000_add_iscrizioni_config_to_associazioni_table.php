<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modulo iscrizioni (PIANO-ISCRIZIONI §Schema dati): apertura iscrizioni
 * socio dal frontend pubblico, modalità di iscrizione e schema del form
 * personalizzabile per tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('associazioni', function (Blueprint $table) {
            $table->boolean('iscrizioni_socio_aperte')->default(true)->after('frontend_pubblico_attivo');
            // Valori ammessi: 'preiscrizione' (Socio "In Attesa", approva l'admin) | 'diretta'
            $table->string('modalita_iscrizione', 20)->default('preiscrizione')->after('iscrizioni_socio_aperte');
            // Schema del form di iscrizione (intro_html, campi_standard, campi_personalizzati)
            $table->json('iscrizione_form_config')->nullable()->after('modalita_iscrizione');
        });
    }

    public function down(): void
    {
        Schema::table('associazioni', function (Blueprint $table) {
            $table->dropColumn([
                'iscrizioni_socio_aperte',
                'modalita_iscrizione',
                'iscrizione_form_config',
            ]);
        });
    }
};
