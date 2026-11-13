<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Conferma delle cariche dopo la proclamazione di un'elezione: chi proclama
 * con il solo permesso riunioni.update registra gli eletti, ma la
 * sostituzione delle cariche nel registro aspetta la conferma di un
 * admin_associazione (CaricaPolicy::create). Le elezioni già proclamate
 * hanno già aggiornato le cariche: la colonna si valorizza con la data
 * della proclamazione, così restano come sono.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('elezioni', function (Blueprint $table): void {
            $table->dateTime('cariche_confermate_at')->nullable()->after('proclamata_at');
        });

        DB::table('elezioni')
            ->where('stato', 'proclamata')
            ->whereNull('cariche_confermate_at')
            ->update(['cariche_confermate_at' => DB::raw('COALESCE(proclamata_at, updated_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('elezioni', function (Blueprint $table): void {
            $table->dropColumn('cariche_confermate_at');
        });
    }
};
