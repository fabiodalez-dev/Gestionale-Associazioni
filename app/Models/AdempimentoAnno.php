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
 * Riga della checklist annuale degli adempimenti (f.20 del piano
 * "Organizzazione 3"): una per adempimento+anno, con completamento
 * tracciato (quando e da chi).
 */
class AdempimentoAnno extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'adempimenti_anni';

    protected $fillable = [
        'associazione_id',
        'adempimento_id',
        'anno',
        'completato_at',
        'completato_da',
    ];

    protected function casts(): array
    {
        return [
            'anno' => 'integer',
            'completato_at' => 'datetime',
        ];
    }

    public function isCompletato(): bool
    {
        return $this->completato_at !== null;
    }

    /**
     * @param  Builder<AdempimentoAnno>  $query
     * @return Builder<AdempimentoAnno>
     */
    public function scopeDellAnno(Builder $query, int $anno): Builder
    {
        return $query->where('anno', $anno);
    }

    /**
     * @return BelongsTo<Adempimento, $this>
     */
    public function adempimento(): BelongsTo
    {
        return $this->belongsTo(Adempimento::class, 'adempimento_id');
    }

    /**
     * Utente che ha spuntato la voce della checklist.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function completatoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'completato_da');
    }
}
