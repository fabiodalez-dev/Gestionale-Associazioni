<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verbali', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('titolo');
            $table->date('data_riunione');
            // Valori ammessi: 'Assemblea Ordinaria' | 'Assemblea Straordinaria' | 'Consiglio Direttivo' | 'Commissione' (ex ENUM MySQL)
            $table->string('tipo_riunione', 50);
            $table->text('presenti')->nullable();
            $table->text('ordine_del_giorno')->nullable();
            $table->text('deliberazioni')->nullable();
            $table->string('allegati', 500)->nullable();
            $table->boolean('approvato')->default(false);
            $table->uuid('approvato_da')->nullable()->index();
            $table->date('data_approvazione')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // FK assenti nel legacy, aggiunte in Laravel (nota schema-dati §3)
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('approvato_da')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verbali');
    }
};
