<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Milestone di progetto (f.16 del piano "Organizzazione 3"): voce della
 * checklist con scadenza opzionale, ordine manuale e completamento.
 */
class MilestoneProgetto extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'milestone_progetto';

    protected $fillable = [
        'associazione_id',
        'progetto_id',
        'titolo',
        'scadenza',
        'completata_at',
        'ordine',
    ];

    protected function casts(): array
    {
        return [
            'scadenza' => 'date',
            'completata_at' => 'datetime',
            'ordine' => 'integer',
        ];
    }

    public function isCompletata(): bool
    {
        return $this->completata_at !== null;
    }

    /**
     * Milestone non completata oltre la scadenza (evidenza scadute, f.16).
     */
    public function isScaduta(): bool
    {
        return ! $this->isCompletata()
            && $this->scadenza !== null
            && $this->scadenza->lt(now()->startOfDay());
    }

    /**
     * @param  Builder<MilestoneProgetto>  $query
     * @return Builder<MilestoneProgetto>
     */
    public function scopeCompletate(Builder $query): Builder
    {
        return $query->whereNotNull('completata_at');
    }

    /**
     * @return BelongsTo<Progetto, $this>
     */
    public function progetto(): BelongsTo
    {
        return $this->belongsTo(Progetto::class, 'progetto_id');
    }
}
