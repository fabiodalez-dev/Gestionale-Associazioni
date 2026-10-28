<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bilancio sociale annuale (issue #15): uno per associazione e anno, con lo
 * snapshot dei dati calcolati dal gestionale (rigenerabile), i testi scritti
 * per ogni sezione delle linee guida, il verbale dell'assemblea che lo
 * approva e il PDF pubblicato tra i documenti della sezione Trasparenza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bilanci_sociali', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->unsignedSmallInteger('anno');
            // Valori ammessi: vedi BilancioSociale::STATI
            $table->string('stato', 20)->default('bozza');
            $table->json('dati')->nullable();
            $table->json('testi')->nullable();
            $table->uuid('verbale_id')->nullable();
            $table->uuid('documento_id')->nullable();
            $table->dateTime('generato_at')->nullable();
            $table->dateTime('approvato_at')->nullable();
            $table->dateTime('pubblicato_at')->nullable();
            $table->timestamps();

            $table->unique(['associazione_id', 'anno'], 'uq_bilanci_sociali_anno');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('verbale_id')->references('id')->on('verbali')->nullOnDelete();
            $table->foreign('documento_id')->references('id')->on('documenti')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bilanci_sociali');
    }
};
