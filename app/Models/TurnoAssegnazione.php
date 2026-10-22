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
 * Assegnazione di un socio a un turno (f.3 del piano "Organizzazione 3"):
 * proposta dall'admin o auto-candidatura del socio (f.4), con ore effettive
 * registrate al completamento per il registro ore volontariato (f.5).
 * Unique turno+socio.
 */
class TurnoAssegnazione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use NelPerimetroSedeTramite;

    public const STATO_PROPOSTO = 'proposto';

    public const STATO_CONFERMATO = 'confermato';

    public const STATO_RINUNCIATO = 'rinunciato';

    public const STATI = [
        self::STATO_PROPOSTO,
        self::STATO_CONFERMATO,
        self::STATO_RINUNCIATO,
    ];

    protected $table = 'turni_assegnazioni';

    protected $fillable = [
        'associazione_id',
        'turno_id',
        'socio_id',
        'stato',
        'ore_effettive',
        'motivo_rinuncia',
    ];

    protected function casts(): array
    {
        return [
            'ore_effettive' => 'decimal:2',
        ];
    }

    public function isConfermata(): bool
    {
        return $this->stato === self::STATO_CONFERMATO;
    }

    /**
     * @param  Builder<TurnoAssegnazione>  $query
     * @return Builder<TurnoAssegnazione>
     */
    public function scopeConfermate(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_CONFERMATO);
    }

    /**
     * Assegnazioni con ore registrate (registro ore volontariato, f.5).
     *
     * @param  Builder<TurnoAssegnazione>  $query
     * @return Builder<TurnoAssegnazione>
     */
    public function scopeConOreRegistrate(Builder $query): Builder
    {
        return $query->whereNotNull('ore_effettive');
    }

    /**
     * @return BelongsTo<Turno, $this>
     */
    public function turno(): BelongsTo
    {
        return $this->belongsTo(Turno::class, 'turno_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
