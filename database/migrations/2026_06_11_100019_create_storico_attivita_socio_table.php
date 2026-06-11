<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('storico_attivita_socio', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('socio_id')->index();
            $table->uuid('associazione_id')->index();
            $table->uuid('utente_id')->nullable()->index();
            // Es.: 'creazione', 'modifica', 'pagamento_quota', 'emissione_tessera', 'cambio_stato', 'comunicazione'
            $table->string('tipo_attivita', 50);
            $table->text('descrizione');
            $table->json('dettagli_json')->nullable();
            $table->dateTime('data_attivita')->nullable()->useCurrent();

            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('utente_id')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storico_attivita_socio');
    }
};
