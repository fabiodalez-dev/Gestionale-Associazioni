<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('soci', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->uuid('sede_id')->nullable()->index();
            $table->string('numero_socio', 50);
            $table->string('nome', 100);
            $table->string('cognome', 100);
            // Nel dump è NOT NULL, in schema.sql nullable (preiscrizione): si segue la direzione evolutiva
            $table->date('data_nascita')->nullable();
            $table->string('codice_fiscale', 16)->nullable();
            $table->string('email');
            $table->string('telefono', 20)->nullable();
            $table->string('indirizzo')->nullable();
            $table->string('citta', 100)->nullable();
            $table->string('provincia', 2)->nullable();
            $table->string('cap', 5)->nullable();
            $table->date('data_iscrizione');
            // Valori ammessi: 'Attivo' | 'Sospeso' | 'Radiato' | 'Deceduto' | 'Trasferito' | 'In Attesa' | 'In Attesa di Pagamento' (ex ENUM MySQL)
            $table->string('stato', 30)->default('Attivo');
            $table->text('note')->nullable();
            $table->boolean('privacy_consenso')->default(false);
            $table->boolean('email_opt_out')->default(false);
            $table->char('email_opt_out_token', 64)->nullable();
            $table->uuid('tipo_socio_id')->nullable()->index();
            $table->uuid('categoria_socio_id')->nullable()->index();
            $table->string('password_hash')->nullable();
            $table->string('password_reset_token')->nullable();
            $table->dateTime('password_reset_expires')->nullable();
            $table->char('rinnovo_token', 64)->nullable()->index();
            $table->dateTime('rinnovo_token_expires')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['associazione_id', 'numero_socio']);
            $table->unique(['associazione_id', 'email']);
            // uniq_assoc_cf: presente nel dump reale (CF vuoto va normalizzato a NULL a livello applicativo)
            $table->unique(['associazione_id', 'codice_fiscale'], 'uniq_assoc_cf');
            $table->index(['cognome', 'nome']);

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('sede_id')->references('id')->on('sedi')->nullOnDelete();
            $table->foreign('tipo_socio_id')->references('id')->on('tipi_socio')->nullOnDelete();
            $table->foreign('categoria_socio_id')->references('id')->on('categorie_socio')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('soci');
    }
};
