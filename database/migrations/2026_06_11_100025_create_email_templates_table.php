<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->string('codice', 50);
            $table->string('nome');
            $table->string('oggetto', 500)->default('');
            $table->text('corpo_html');
            $table->text('corpo_json')->nullable();
            $table->boolean('attivo')->default(true);
            $table->timestamps();

            $table->unique(['associazione_id', 'codice'], 'uniq_template');
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};
