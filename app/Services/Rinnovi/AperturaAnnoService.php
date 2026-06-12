<?php

namespace App\Services\Rinnovi;

use App\Models\Associazione;
use App\Models\Quota;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Apertura dell'anno sociale (f.5 del piano Vita sociale): generazione
 * in bulk delle quote del nuovo anno per i soci Attivi del tenant.
 *
 * Idempotente: i soci che hanno già una quota per l'anno vengono saltati.
 * Importo per socio: costo tessera del tipo socio con fallback sul costo
 * tessera dell'associazione (0 se non configurato).
 */
class AperturaAnnoService
{
    public const TIPO_QUOTA = 'Quota Associativa';

    /**
     * Anteprima dell'apertura: numeri e importi senza scrivere nulla.
     *
     * @return array{
     *     soci_attivi: int,
     *     gia_aperte: int,
     *     da_generare: int,
     *     importo_totale: float,
     *     per_tipo: list<array{tipo: string, soci: int, importo_unitario: float, importo_totale: float}>
     * }
     */
    public function anteprima(Associazione $associazione, int $anno): array
    {
        $sociAttivi = $this->querySociAttivi($associazione)->count();

        $daGenerare = $this->querySociDaAprire($associazione, $anno)->with('tipoSocio')->get();

        $perTipo = $daGenerare
            ->groupBy(fn (Socio $s) => $s->tipoSocio->nome ?? 'Senza tipo')
            ->map(function ($soci, string $tipo) use ($associazione) {
                /** @var Socio $primo */
                $primo = $soci->first();
                $unitario = $this->importoPerSocio($primo, $associazione);

                return [
                    'tipo' => $tipo,
                    'soci' => $soci->count(),
                    'importo_unitario' => $unitario,
                    'importo_totale' => round($soci->sum(fn (Socio $s) => $this->importoPerSocio($s, $associazione)), 2),
                ];
            })
            ->sortBy('tipo')
            ->values()
            ->all();

        return [
            'soci_attivi' => $sociAttivi,
            'gia_aperte' => $sociAttivi - $daGenerare->count(),
            'da_generare' => $daGenerare->count(),
            'importo_totale' => round($daGenerare->sum(fn (Socio $s) => $this->importoPerSocio($s, $associazione)), 2),
            'per_tipo' => $perTipo,
        ];
    }

    /**
     * Genera in bulk le quote dell'anno per i soci attivi senza quota.
     * Restituisce il numero di quote create.
     */
    public function apri(Associazione $associazione, int $anno): int
    {
        return DB::transaction(function () use ($associazione, $anno): int {
            $create = 0;
            $scadenza = Carbon::create($anno, 12, 31)->toDateString();

            $this->querySociDaAprire($associazione, $anno)
                ->with('tipoSocio')
                ->chunkById(200, function ($soci) use ($associazione, $anno, $scadenza, &$create): void {
                    foreach ($soci as $socio) {
                        Quota::create([
                            'associazione_id' => $associazione->id,
                            'socio_id' => $socio->id,
                            'anno' => $anno,
                            'importo' => $this->importoPerSocio($socio, $associazione),
                            'data_scadenza' => $scadenza,
                            'data_pagamento' => null,
                            'stato' => Quota::STATO_DA_PAGARE,
                            'tipo' => self::TIPO_QUOTA,
                        ]);
                        $create++;
                    }
                });

            return $create;
        });
    }

    public function importoPerSocio(Socio $socio, Associazione $associazione): float
    {
        return (float) ($socio->tipoSocio->costo_tessera ?? $associazione->costo_tessera ?? 0);
    }

    /**
     * @return Builder<Socio>
     */
    private function querySociAttivi(Associazione $associazione)
    {
        return Socio::query()
            ->forAssociazione($associazione->id)
            ->where('stato', Socio::STATO_ATTIVO);
    }

    /**
     * Soci attivi SENZA quota per l'anno (qualunque stato della quota).
     *
     * @return Builder<Socio>
     */
    private function querySociDaAprire(Associazione $associazione, int $anno)
    {
        return $this->querySociAttivi($associazione)
            ->whereNotExists(function ($q) use ($associazione, $anno) {
                $q->selectRaw('1')
                    ->from('quote')
                    ->whereColumn('quote.socio_id', 'soci.id')
                    ->where('quote.associazione_id', $associazione->id)
                    ->where('quote.anno', $anno);
            });
    }
}
