<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tags', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id');
            $table->string('nome_tag', 50);
            $table->string('colore', 7)->nullable()->default('#888888');
            $table->timestamps();

            $table->unique(['associazione_id', 'nome_tag']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tags');
    }
};
