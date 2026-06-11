<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->uuid('user_id')->nullable()->index();
            $table->string('scope', 50)->default('soci');
            $table->string('name');
            $table->longText('params_json');
            $table->timestamps();

            $table->unique(['associazione_id', 'user_id', 'scope', 'name'], 'uniq_filter');
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('utenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
