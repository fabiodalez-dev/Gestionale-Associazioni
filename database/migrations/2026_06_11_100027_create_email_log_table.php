<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_log', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('batch_id')->nullable()->index();
            $table->uuid('socio_id')->nullable()->index();
            $table->string('to_email');
            $table->string('to_name')->nullable()->default('');
            $table->string('subject', 500);
            $table->string('template_codice', 50)->nullable();
            // Valori ammessi: 'sent' | 'failed' (ex ENUM MySQL) — log append-only di soli esiti definitivi
            $table->string('status', 10);
            $table->text('error_message')->nullable();
            $table->uuid('sent_by')->nullable()->index();
            $table->dateTime('sent_at')->nullable()->useCurrent()->index();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->nullOnDelete();
            $table->foreign('sent_by')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_log');
    }
};
