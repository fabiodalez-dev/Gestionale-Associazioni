<?php

namespace App\Models\Concerns;

use App\Support\PerimetroSedi;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scope per sede sui modelli con colonna sede_id (vedi PerimetroSedi).
 * Con la costante SEDE_FACOLTATIVA = true i record senza sede (di tutta
 * l'associazione) restano visibili anche agli utenti limitati.
 */
trait NelPerimetroSede
{
    public static function bootNelPerimetroSede(): void
    {
        static::addGlobalScope('sede', function (Builder $builder): void {
            $sedi = PerimetroSedi::sedi();
            if ($sedi === null) {
                return;
            }

            $colonna = $builder->qualifyColumn('sede_id');
            $facoltativa = defined(static::class.'::SEDE_FACOLTATIVA') && constant(static::class.'::SEDE_FACOLTATIVA');

            $builder->where(function (Builder $q) use ($colonna, $sedi, $facoltativa): void {
                $q->whereIn($colonna, $sedi);
                if ($facoltativa) {
                    $q->orWhereNull($colonna);
                }
            });
        });
    }
}
