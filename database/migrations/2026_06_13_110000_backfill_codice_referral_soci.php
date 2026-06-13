<?php

use App\Models\Socio;
use Illuminate\Database\Migrations\Migration;

/**
 * Backfill del codice referral "porta un amico" (f.23) sui soci preesistenti.
 *
 * La colonna `soci.codice_referral` è stata aggiunta come nullable da
 * 2026_06_13_100000_add_organizzazione_foundation.php, e il codice viene
 * generato solo nell'evento `creating` del model Socio. Su un upgrade in-place
 * con tabella `soci` già popolata, tutti i soci esistenti restano con
 * `codice_referral = NULL` → la card "Porta un amico" non viene mostrata e non
 * possono entrare nella classifica ambasciatori.
 *
 * Questa migration popola il codice per ogni socio con `codice_referral IS NULL`
 * (inclusi i soft-deleted, che condividono il vincolo unique e possono essere
 * ripristinati), riusando Socio::generaCodiceReferral() per garantire l'unicità.
 *
 * Idempotente: tocca solo i soci ancora privi di codice, quindi è sicura da
 * rieseguire. SQLite-compatibile: itera in PHP, niente SQL raw di generazione.
 */
return new class extends Migration
{
    public function up(): void
    {
        // allTenants() ignora il global scope multi-tenant; withTrashed() include
        // i soci soft-deleted. In contesto migration non c'è tenant in sessione,
        // ma siamo espliciti per non dipendere dal contesto.
        Socio::allTenants()
            ->withTrashed()
            ->whereNull('codice_referral')
            ->select(['id'])
            ->chunkById(500, function ($soci) {
                foreach ($soci as $socio) {
                    // generaCodiceReferral() ricontrolla l'unicità su tutti i
                    // tenant (withTrashed) ad ogni iterazione: poiché l'update è
                    // persistito subito, le iterazioni successive vedono il codice
                    // appena assegnato ed evitano collisioni nello stesso batch.
                    $codice = Socio::generaCodiceReferral();

                    // Aggiornamento diretto: niente eventi/observer/activity-log,
                    // niente modifica di updated_at sui soci preesistenti.
                    Socio::allTenants()
                        ->withTrashed()
                        ->whereKey($socio->id)
                        ->toBase()
                        ->update(['codice_referral' => $codice]);
                }
            });
    }

    public function down(): void
    {
        // Irreversibile per design: non distinguiamo i codici generati dal
        // backfill da quelli creati naturalmente alla creazione del socio.
        // Azzerarli romperebbe i link di invito già condivisi.
    }
};
