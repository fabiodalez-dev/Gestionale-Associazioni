<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account ospite del frontend pubblico: entità separata dai soci,
 * promuovibile a socio (socio_id unique, mai collegato in automatico).
 * Email univoca per tenant (stessa email su associazioni diverse = ok).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('nome', 100);
            $table->string('cognome', 100);
            $table->string('email');
            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('telefono', 20)->nullable();
            $table->uuid('socio_id')->nullable()->unique();
            $table->boolean('attivo')->default(true);
            $table->json('preferenze')->nullable();
            $table->dateTime('privacy_accettata_at')->nullable();
            $table->dateTime('richiesta_socio_at')->nullable();
            $table->dateTime('ultimo_accesso_at')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['associazione_id', 'email']);

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
