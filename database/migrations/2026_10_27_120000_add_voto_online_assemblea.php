<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Votazioni dei soci in assemblea (issue #8): la segreteria apre e chiude il
 * voto su un punto dell'OdG e i soci presenti votano dall'area soci, anche
 * per i deleganti. Due tabelle separate:
 * - votanti_riunione: CHI ha votato e per conto di chi (impedisce il doppio
 *   voto, unique votazione + rappresentato);
 * - voti_riunione: COSA è stato votato. Nel voto segreto il socio non è
 *   salvato e la riga non ha data, così non si può collegare al votante.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riunioni', function (Blueprint $table) {
            $table->boolean('voto_online')->default(false)->after('deleghe_vietate_cariche');
            // Il socio può segnalare la presenza dall'area soci (assemblea a distanza)
            $table->boolean('partecipazione_remota')->default(false)->after('voto_online');
        });

        Schema::table('votazioni_riunione', function (Blueprint $table) {
            $table->boolean('segreta')->default(false)->after('astenuti');
            // Voto online: aperto dalla segreteria, chiuso con il conteggio
            $table->dateTime('aperta_at')->nullable()->after('segreta');
            $table->dateTime('chiusa_at')->nullable()->after('aperta_at');
        });

        Schema::create('votanti_riunione', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('votazione_id');
            // Socio che ha espresso il voto (presente)
            $table->uuid('votante_socio_id');
            // Socio per cui vale il voto: sé stesso o un delegante
            $table->uuid('rappresentato_socio_id');
            $table->timestamps();

            $table->unique(['votazione_id', 'rappresentato_socio_id'], 'uq_votanti_riunione_rappresentato');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('votazione_id')->references('id')->on('votazioni_riunione')->cascadeOnDelete();
            $table->foreign('votante_socio_id')->references('id')->on('soci')->cascadeOnDelete();
            $table->foreign('rappresentato_socio_id')->references('id')->on('soci')->cascadeOnDelete();
        });

        Schema::create('voti_riunione', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('associazione_id')->index();
            $table->uuid('votazione_id');
            // Valori ammessi: vedi VotoRiunione::OPZIONI
            $table->string('opzione', 20);
            // Solo nel voto palese: socio per cui vale il voto
            $table->uuid('socio_id')->nullable();

            $table->index(['votazione_id', 'opzione'], 'idx_voti_riunione_opzione');

            $table->foreign('associazione_id')->references('id')->on('associazioni')->cascadeOnDelete();
            $table->foreign('votazione_id')->references('id')->on('votazioni_riunione')->cascadeOnDelete();
            $table->foreign('socio_id')->references('id')->on('soci')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voti_riunione');
        Schema::dropIfExists('votanti_riunione');

        Schema::table('votazioni_riunione', function (Blueprint $table) {
            $table->dropColumn(['segreta', 'aperta_at', 'chiusa_at']);
        });

        Schema::table('riunioni', function (Blueprint $table) {
            $table->dropColumn(['voto_online', 'partecipazione_remota']);
        });
    }
};
