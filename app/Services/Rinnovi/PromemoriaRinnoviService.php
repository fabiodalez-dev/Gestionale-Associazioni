<?php

namespace App\Services\Rinnovi;

use App\Models\Associazione;
use App\Models\PromemoriaInviato;
use App\Models\Socio;
use App\Models\Tessera;
use App\Notifications\Rinnovi\PromemoriaRinnovoNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Promemoria rinnovo multi-step (f.3 del piano Vita sociale).
 *
 * Per ogni tessera Attiva in scadenza entro lo step massimo del tenant
 * (associazioni.promemoria_rinnovo_config, default [30, 15, 3]) invia al
 * socio UNA notifica per esecuzione e traccia in promemoria_inviati tutti
 * gli step già "consumati" (giorni residui <= step) per non duplicare:
 * l'unique a DB (socio, tessera, tipo, step) è la rete di sicurezza.
 */
class PromemoriaRinnoviService
{
    /**
     * Elabora un tenant: restituisce il numero di promemoria inviati.
     */
    public function processa(Associazione $associazione): int
    {
        $steps = $associazione->promemoriaRinnovoSteps(); // decrescenti

        if ($steps === []) {
            return 0;
        }

        $oggi = now()->startOfDay();
        $limite = $oggi->copy()->addDays(max($steps));
        $inviati = 0;

        Tessera::allTenants()
            ->where('tessere.associazione_id', $associazione->id)
            ->where('tessere.stato', Tessera::STATO_ATTIVA)
            ->whereDate('tessere.data_scadenza', '>=', $oggi->toDateString())
            ->whereDate('tessere.data_scadenza', '<=', $limite->toDateString())
            ->with(['socio' => fn ($q) => $q->withoutGlobalScopes()])
            ->orderBy('tessere.data_scadenza')
            ->chunkById(200, function ($tessere) use ($associazione, $steps, $oggi, &$inviati): void {
                foreach ($tessere as $tessera) {
                    if ($this->invia($associazione, $tessera, $steps, $oggi)) {
                        $inviati++;
                    }
                }
            }, 'tessere.id', 'id');

        return $inviati;
    }

    /**
     * Valuta una singola tessera: invia al massimo UNA notifica e registra
     * tutti gli step consumati. True se la notifica è stata inviata.
     *
     * @param  list<int>  $steps
     */
    private function invia(Associazione $associazione, Tessera $tessera, array $steps, Carbon $oggi): bool
    {
        /** @var Socio|null $socio */
        $socio = $tessera->socio;

        // Solo soci attivi (niente promemoria a sospesi/cessati/cestinati)
        if ($socio === null || $socio->deleted_at !== null || ! $socio->isAttivo()) {
            return false;
        }

        // Già rinnovato: esiste una tessera più recente del socio → niente promemoria
        $giaRinnovato = Tessera::allTenants()
            ->where('associazione_id', $associazione->id)
            ->where('socio_id', $socio->id)
            ->where('anno_validita', '>', $tessera->anno_validita)
            ->exists();

        if ($giaRinnovato) {
            return false;
        }

        $giorniResidui = (int) $oggi->diffInDays($tessera->data_scadenza->startOfDay(), false);

        $giaInviati = PromemoriaInviato::allTenants()
            ->where('socio_id', $socio->id)
            ->where('tessera_id', $tessera->id)
            ->where('tipo', PromemoriaInviato::TIPO_RINNOVO_TESSERA)
            ->pluck('step')
            ->map(fn ($s) => (int) $s)
            ->all();

        // Step in finestra (giorni residui <= step) non ancora tracciati
        $daTracciare = array_values(array_filter(
            $steps,
            fn (int $step) => $giorniResidui <= $step && ! in_array($step, $giaInviati, true)
        ));

        if ($daTracciare === []) {
            return false;
        }

        // Una sola email per esecuzione: lo step "corrente" è il più vicino alla scadenza
        $stepCorrente = min($daTracciare);

        foreach ($daTracciare as $step) {
            PromemoriaInviato::create([
                'associazione_id' => $associazione->id,
                'socio_id' => $socio->id,
                'tessera_id' => $tessera->id,
                'tipo' => PromemoriaInviato::TIPO_RINNOVO_TESSERA,
                'step' => $step,
                'inviato_at' => now(),
            ]);
        }

        try {
            $socio->notify(new PromemoriaRinnovoNotification($tessera, $stepCorrente));
        } catch (\Throwable $e) {
            Log::warning('PromemoriaRinnoviService: notifica fallita', [
                'socio_id' => $socio->id,
                'tessera_id' => $tessera->id,
                'error' => $e->getMessage(),
            ]);
        }

        return true;
    }
}
