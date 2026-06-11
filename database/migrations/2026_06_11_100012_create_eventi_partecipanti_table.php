<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('eventi_partecipanti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('evento_id');
            $table->uuid('socio_id')->index();
            // Valori ammessi: 'Confermato' | 'Forse' | 'Non Partecipa' (ex ENUM MySQL)
            $table->string('stato_partecipazione', 20)->default('Non Partecipa');
            $table->timestamp('data_conferma')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->unique(['evento_id', 'socio_id'], 'unique_partecipation');
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('evento_id')->references('id')->on('eventi')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eventi_partecipanti');
    }
};
