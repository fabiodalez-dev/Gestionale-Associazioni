<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Models\Concerns\NelPerimetroSedeTramite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Carica negli organi sociali (f.16 del piano): ruolo ricoperto da un
 * socio con inizio/fine mandato. fine NULL = mandato in corso.
 */
class Carica extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use NelPerimetroSedeTramite;

    /** Ruoli suggeriti (la colonna resta una string libera). */
    public const RUOLI = [
        'Presidente',
        'Vicepresidente',
        'Segretario',
        'Tesoriere',
        'Consigliere',
        'Revisore dei Conti',
        'Probiviro',
    ];

    /** Giorni di preavviso per l'alert "mandato in scadenza" in dashboard. */
    public const GIORNI_ALERT_SCADENZA = 90;

    protected $table = 'cariche';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'ruolo',
        'inizio',
        'fine',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'inizio' => 'date',
            'fine' => 'date',
        ];
    }

    public function isAttiva(): bool
    {
        return ! $this->inizio->isFuture()
            && ($this->fine === null || ! $this->fine->isPast());
    }

    /**
     * Cariche attive oggi (mandato iniziato e non ancora terminato).
     *
     * @param  Builder<Carica>  $query
     * @return Builder<Carica>
     */
    public function scopeAttive(Builder $query): Builder
    {
        $oggi = now()->toDateString();

        return $query->whereDate('inizio', '<=', $oggi)
            ->where(function (Builder $q) use ($oggi) {
                $q->whereNull('fine')->orWhereDate('fine', '>=', $oggi);
            });
    }

    /**
     * Cariche attive con mandato in scadenza entro $giorni (default 90, f.16).
     *
     * @param  Builder<Carica>  $query
     * @return Builder<Carica>
     */
    public function scopeInScadenza(Builder $query, int $giorni = self::GIORNI_ALERT_SCADENZA): Builder
    {
        return $query->attive()
            ->whereNotNull('fine')
            ->whereDate('fine', '<=', now()->addDays($giorni)->toDateString());
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
