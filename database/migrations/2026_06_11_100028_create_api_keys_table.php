<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL = chiave globale super_admin (accesso cross-tenant)
            $table->uuid('associazione_id')->nullable()->index();
            $table->string('api_key', 64)->unique();
            $table->string('nome');
            $table->json('permessi');
            $table->boolean('attiva')->default(true);
            $table->dateTime('ultimo_utilizzo')->nullable();
            $table->date('scadenza')->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
