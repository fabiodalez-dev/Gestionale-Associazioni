<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_queue', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('batch_id')->index();
            $table->uuid('socio_id')->nullable()->index();
            $table->string('to_email');
            $table->string('to_name')->nullable()->default('');
            $table->string('subject', 500);
            $table->text('body_html');
            $table->string('template_codice', 50)->nullable();
            $table->tinyInteger('priority')->default(5);
            // Valori ammessi: 'pending' | 'sending' | 'sent' | 'failed' | 'cancelled' (ex ENUM MySQL)
            $table->string('status', 20)->default('pending');
            $table->integer('attempts')->default(0);
            $table->integer('max_attempts')->default(3);
            $table->dateTime('last_attempt_at')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('sent_at')->nullable();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->index(['status', 'created_at'], 'idx_eq_status');
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_queue');
    }
};
