<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Candidatura a un'elezione (issue #9). Valida quando accettata dal
 * candidato (subito per le autocandidature) e non ritirata. Dopo lo
 * scrutinio porta preferenze totali, posizione ed elezione.
 */
class Candidatura extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'candidature';

    protected $fillable = [
        'associazione_id',
        'elezione_id',
        'socio_id',
        'presentazione',
        'origine',
        'accettata_at',
        'ritirata_at',
        'preferenze_sala',
        'preferenze_totali',
        'posizione',
        'eletta',
    ];

    protected function casts(): array
    {
        return [
            'accettata_at' => 'datetime',
            'ritirata_at' => 'datetime',
            'preferenze_sala' => 'integer',
            'preferenze_totali' => 'integer',
            'posizione' => 'integer',
            'eletta' => 'boolean',
        ];
    }

    public function isValida(): bool
    {
        return $this->accettata_at !== null && $this->ritirata_at === null;
    }

    /**
     * Candidature accettate e non ritirate: quelle sulla scheda.
     *
     * @param  Builder<Candidatura>  $query
     * @return Builder<Candidatura>
     */
    public function scopeValide(Builder $query): Builder
    {
        return $query->whereNotNull('accettata_at')->whereNull('ritirata_at');
    }

    /**
     * @return BelongsTo<Elezione, $this>
     */
    public function elezione(): BelongsTo
    {
        return $this->belongsTo(Elezione::class, 'elezione_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
