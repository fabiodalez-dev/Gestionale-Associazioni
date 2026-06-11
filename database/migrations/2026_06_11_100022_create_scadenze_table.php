<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scadenze', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('titolo');
            $table->text('descrizione')->nullable();
            $table->date('data_scadenza');
            // Valori ammessi: 'Quote' | 'Documenti' | 'Eventi' | 'Generale' (ex ENUM MySQL)
            $table->string('tipo_scadenza', 20);
            // Valori ammessi: 'Bassa' | 'Media' | 'Alta' | 'Critica' (ex ENUM MySQL)
            $table->string('priorita', 20)->default('Media');
            // Valori ammessi: 'Attiva' | 'Completata' | 'Annullata' (ex ENUM MySQL)
            $table->string('stato', 20)->default('Attiva');
            $table->uuid('assegnato_a')->nullable()->index();
            $table->integer('promemoria_giorni')->default(7);
            $table->timestamps();

            // FK assenti nel legacy, aggiunte in Laravel (nota schema-dati §3)
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('assegnato_a')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scadenze');
    }
};
