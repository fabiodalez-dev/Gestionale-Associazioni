<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->unique('uniq_smtp_assoc');
            $table->string('smtp_host')->default('');
            $table->integer('smtp_port')->default(587);
            $table->string('smtp_user')->default('');
            $table->text('smtp_pass_encrypted');
            // Valori ammessi: 'tls' | 'ssl' | 'none' (ex ENUM MySQL)
            $table->string('smtp_encryption', 10)->default('tls');
            $table->string('from_email')->default('');
            $table->string('from_name')->default('');
            $table->string('reply_to_email')->nullable();
            $table->string('reply_to_name')->nullable();
            $table->boolean('is_verified')->default(false);
            $table->integer('max_per_hour')->default(100);
            $table->boolean('auto_benvenuto')->default(true);
            $table->boolean('auto_scadenza_tessera')->default(true);
            $table->boolean('auto_scadenza_quota')->default(true);
            $table->boolean('auto_rinnovo_tessera')->default(false);
            $table->boolean('auto_pagamento_quota')->default(false);
            $table->dateTime('last_test_at')->nullable();
            $table->timestamps();

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};
