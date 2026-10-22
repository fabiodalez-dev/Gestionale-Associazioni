<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Impostazioni per associazione (spatie/laravel-settings + TenantSettingsRepository):
 * associazione_id NULL = impostazione di piattaforma, altrimenti una riga per
 * associazione. La tabella era vuota: nessuna classe Settings la usava.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->uuid('associazione_id')->nullable()->after('id');
            $table->dropUnique(['group', 'name']);
            $table->unique(['associazione_id', 'group', 'name']);
            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropForeign(['associazione_id']);
            $table->dropUnique(['associazione_id', 'group', 'name']);
            $table->dropColumn('associazione_id');
            $table->unique(['group', 'name']);
        });
    }
};
