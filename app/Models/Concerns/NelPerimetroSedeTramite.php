<?php

namespace App\Models\Concerns;

use App\Support\PerimetroSedi;
use Illuminate\Database\Eloquent\Builder;

/**
 * Scope per sede sui dati collegati a un socio o a un evento (tessere,
 * quote, documenti, prenotazioni…): visibili solo se il record collegato è
 * nel perimetro dell'utente. La relazione si indica con la costante
 * PERIMETRO_TRAMITE (default "socio"); il whereHas usa gli scope del modello
 * collegato, quindi il filtro è lo stesso della sua lista.
 */
trait NelPerimetroSedeTramite
{
    public static function bootNelPerimetroSedeTramite(): void
    {
        static::addGlobalScope('sede', function (Builder $builder): void {
            if (! PerimetroSedi::limitato()) {
                return;
            }

            $relazione = defined(static::class.'::PERIMETRO_TRAMITE') ? constant(static::class.'::PERIMETRO_TRAMITE') : 'socio';

            // PERIMETRO_FACOLTATIVO: i record senza collegamento (es. turno senza
            // evento) sono di tutta l'associazione e restano visibili
            if (defined(static::class.'::PERIMETRO_FACOLTATIVO') && constant(static::class.'::PERIMETRO_FACOLTATIVO')) {
                $chiave = $builder->qualifyColumn($builder->getModel()->{$relazione}()->getForeignKeyName());
                $builder->where(fn (Builder $q) => $q->whereHas($relazione)->orWhereNull($chiave));

                return;
            }

            $builder->whereHas($relazione);
        });
    }
}
