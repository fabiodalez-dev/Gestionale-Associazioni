<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socio_tags', function (Blueprint $table) {
            $table->uuid('socio_id');
            $table->uuid('tag_id')->index();
            $table->timestamp('created_at')->nullable()->useCurrent();

            $table->primary(['socio_id', 'tag_id']);
            $table->foreign('socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('tag_id')->references('id')->on('tags')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socio_tags');
    }
};
