<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Models\Concerns\NelPerimetroSedeTramite;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Turno di volontariato (f.3 del piano "Organizzazione 3"): data, fascia
 * oraria e posti, con mansione richiesta e collegamenti opzionali a evento,
 * spazio e progetto (f.14). auto_conferma (f.4) conferma subito le
 * auto-candidature dei soci senza passare dall'approvazione admin.
 */
class Turno extends Model
{
    use NelPerimetroSedeTramite;

    /** Perimetro tramite l'evento; i turni senza evento sono di tutta l'associazione. */
    public const PERIMETRO_TRAMITE = 'evento';

    public const PERIMETRO_FACOLTATIVO = true;

    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const STATO_APERTO = 'aperto';

    public const STATO_CHIUSO = 'chiuso';

    public const STATI = [
        self::STATO_APERTO,
        self::STATO_CHIUSO,
    ];

    protected $table = 'turni';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'data',
        'ora_inizio',
        'ora_fine',
        'posti',
        'mansione_id',
        'evento_id',
        'spazio_id',
        'progetto_id',
        'note',
        'stato',
        'auto_conferma',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'posti' => 'integer',
            'auto_conferma' => 'boolean',
        ];
    }

    public function isAperto(): bool
    {
        return $this->stato === self::STATO_APERTO;
    }

    /**
     * Durata del turno in ore (per precompilare le ore effettive, f.5).
     */
    public function durataOre(): float
    {
        $inizio = Carbon::parse($this->ora_inizio);
        $fine = Carbon::parse($this->ora_fine);

        return round(abs($inizio->diffInHours($fine)), 2);
    }

    /**
     * Posti ancora liberi (escluse le assegnazioni rinunciate).
     */
    public function postiDisponibili(): int
    {
        $occupati = $this->assegnazioni()
            ->whereIn('stato', [TurnoAssegnazione::STATO_PROPOSTO, TurnoAssegnazione::STATO_CONFERMATO])
            ->count();

        return max(0, (int) $this->posti - $occupati);
    }

    /**
     * @param  Builder<Turno>  $query
     * @return Builder<Turno>
     */
    public function scopeAperti(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_APERTO);
    }

    /**
     * @return BelongsTo<Mansione, $this>
     */
    public function mansione(): BelongsTo
    {
        return $this->belongsTo(Mansione::class, 'mansione_id');
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    /**
     * @return BelongsTo<Spazio, $this>
     */
    public function spazio(): BelongsTo
    {
        return $this->belongsTo(Spazio::class, 'spazio_id');
    }

    /**
     * @return BelongsTo<Progetto, $this>
     */
    public function progetto(): BelongsTo
    {
        return $this->belongsTo(Progetto::class, 'progetto_id');
    }

    /**
     * @return HasMany<TurnoAssegnazione, $this>
     */
    public function assegnazioni(): HasMany
    {
        return $this->hasMany(TurnoAssegnazione::class, 'turno_id');
    }
}
