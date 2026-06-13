<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Progetto dell'associazione (f.12 del piano "Organizzazione 3"): periodo,
 * responsabile, stato e budget; aggrega movimenti di prima nota (f.13),
 * eventi e turni collegati (f.14) e milestone (f.16) per il report PDF (f.15).
 *
 * @property-read int<0, max>|null $milestones_completate_count alias withCount() (vedi ProgettoController)
 */
class Progetto extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const STATO_BOZZA = 'bozza';

    public const STATO_ATTIVO = 'attivo';

    public const STATO_CONCLUSO = 'concluso';

    public const STATI = [
        self::STATO_BOZZA,
        self::STATO_ATTIVO,
        self::STATO_CONCLUSO,
    ];

    protected $table = 'progetti';

    protected $fillable = [
        'associazione_id',
        'nome',
        'descrizione',
        'dal',
        'al',
        'responsabile_id',
        'stato',
        'budget',
    ];

    protected function casts(): array
    {
        return [
            'dal' => 'date',
            'al' => 'date',
            'budget' => 'decimal:2',
        ];
    }

    /**
     * @param  Builder<Progetto>  $query
     * @return Builder<Progetto>
     */
    public function scopeAttivi(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_ATTIVO);
    }

    /**
     * Percentuale di milestone completate (barra avanzamento, f.16).
     *
     * Usa i contatori già caricati via withCount() (`milestones_count` e
     * `milestones_completate_count`) per evitare N+1 nelle liste; ricade su una
     * query solo quando i contatori non sono stati eager-loaded.
     */
    public function percentualeAvanzamento(): int
    {
        if (array_key_exists('milestones_count', $this->getAttributes())) {
            $totale = (int) $this->milestones_count;

            if ($totale === 0) {
                return 0;
            }

            $completate = array_key_exists('milestones_completate_count', $this->getAttributes())
                ? (int) $this->milestones_completate_count
                : $this->milestones()->whereNotNull('completata_at')->count();

            return (int) round($completate / $totale * 100);
        }

        $totale = $this->milestones()->count();

        if ($totale === 0) {
            return 0;
        }

        $completate = $this->milestones()->whereNotNull('completata_at')->count();

        return (int) round($completate / $totale * 100);
    }

    /**
     * Responsabile del progetto (utente admin).
     *
     * @return BelongsTo<Utente, $this>
     */
    public function responsabile(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'responsabile_id');
    }

    /**
     * @return HasMany<MilestoneProgetto, $this>
     */
    public function milestones(): HasMany
    {
        return $this->hasMany(MilestoneProgetto::class, 'progetto_id');
    }

    /**
     * Movimenti di prima nota imputati al progetto (f.13).
     *
     * @return HasMany<Movimento, $this>
     */
    public function movimenti(): HasMany
    {
        return $this->hasMany(Movimento::class, 'progetto_id');
    }

    /**
     * @return HasMany<Evento, $this>
     */
    public function eventi(): HasMany
    {
        return $this->hasMany(Evento::class, 'progetto_id');
    }

    /**
     * @return HasMany<Turno, $this>
     */
    public function turni(): HasMany
    {
        return $this->hasMany(Turno::class, 'progetto_id');
    }
}
