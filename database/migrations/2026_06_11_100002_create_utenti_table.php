<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('utenti', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->nullable()->index();
            $table->string('nome', 100);
            $table->string('cognome', 100);
            $table->string('email')->unique();
            $table->string('password_hash');
            // Valori ammessi: 'super_admin' | 'admin_associazione' | 'operatore' (ex ENUM MySQL)
            $table->string('ruolo', 30);
            $table->boolean('attivo')->default(true);
            $table->dateTime('last_login')->nullable();
            // Colonne Laravel/Fortify (non presenti nel dump legacy)
            $table->rememberToken();
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utenti');
    }
};
