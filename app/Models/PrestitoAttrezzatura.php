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
 * Prestito di attrezzatura a un socio (f.10 del piano "Organizzazione 3"):
 * restituito_at NULL = prestito attivo; in ritardo se la restituzione
 * prevista è passata senza restituzione.
 */
class PrestitoAttrezzatura extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use NelPerimetroSedeTramite;

    protected $table = 'prestiti_attrezzature';

    protected $fillable = [
        'associazione_id',
        'attrezzatura_id',
        'socio_id',
        'quantita',
        'dal',
        'restituzione_prevista',
        'restituito_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'quantita' => 'integer',
            'dal' => 'date',
            'restituzione_prevista' => 'date',
            'restituito_at' => 'datetime',
        ];
    }

    public function isAttivo(): bool
    {
        return $this->restituito_at === null;
    }

    public function isInRitardo(): bool
    {
        return $this->isAttivo()
            && $this->restituzione_prevista->lt(now()->startOfDay());
    }

    /**
     * Prestiti non ancora restituiti (f.10).
     *
     * @param  Builder<PrestitoAttrezzatura>  $query
     * @return Builder<PrestitoAttrezzatura>
     */
    public function scopeAttivi(Builder $query): Builder
    {
        return $query->whereNull('restituito_at');
    }

    /**
     * Prestiti attivi oltre la data di restituzione prevista (promemoria ritardo).
     *
     * @param  Builder<PrestitoAttrezzatura>  $query
     * @return Builder<PrestitoAttrezzatura>
     */
    public function scopeInRitardo(Builder $query): Builder
    {
        return $query->attivi()
            ->whereDate('restituzione_prevista', '<', now()->toDateString());
    }

    /**
     * @return BelongsTo<Attrezzatura, $this>
     */
    public function attrezzatura(): BelongsTo
    {
        return $this->belongsTo(Attrezzatura::class, 'attrezzatura_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
