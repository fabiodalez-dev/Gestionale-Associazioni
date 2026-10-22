<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Id dell'evento sul Google Calendar dell'associazione
 * (SincronizzaEventoGoogleCalendar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('eventi', function (Blueprint $table): void {
            $table->string('google_event_id', 255)->nullable()->after('galleria_pubblica');
        });
    }

    public function down(): void
    {
        Schema::table('eventi', function (Blueprint $table): void {
            $table->dropColumn('google_event_id');
        });
    }
};
