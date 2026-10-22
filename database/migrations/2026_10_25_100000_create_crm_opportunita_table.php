<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM a colonne: opportunità di nuove adesioni, sponsor e volontari,
 * ognuna in una fase della sua pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_opportunita', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('pipeline', 20);
            $table->string('fase', 30);
            $table->unsignedInteger('posizione')->default(0);
            $table->string('titolo');
            $table->string('descrizione', 500)->nullable();
            $table->string('origine', 20)->nullable();
            $table->decimal('valore', 10, 2)->nullable();
            $table->date('prossima_azione_il')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono', 30)->nullable();
            $table->uuid('responsabile_id')->nullable();
            $table->uuid('socio_id')->nullable();
            $table->uuid('contatto_esterno_id')->nullable();
            $table->string('esito', 10)->nullable(); // vinta | persa
            $table->timestamp('chiusa_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['associazione_id', 'pipeline', 'fase', 'posizione'], 'crm_opp_colonna_idx');
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('responsabile_id')->references('id')->on('utenti')->nullOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->nullOnDelete();
            $table->foreign('contatto_esterno_id')->references('id')->on('contatti_esterni')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_opportunita');
    }
};
