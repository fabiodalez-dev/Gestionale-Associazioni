<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documenti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome_file');
            $table->text('percorso_file');
            $table->text('descrizione')->nullable();
            $table->string('categoria', 100)->nullable();
            $table->integer('dimensione_kb')->nullable();
            $table->uuid('caricato_da')->nullable()->index();
            $table->timestamp('created_at')->nullable()->useCurrent();
            $table->softDeletes();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('caricato_da')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documenti');
    }
};
