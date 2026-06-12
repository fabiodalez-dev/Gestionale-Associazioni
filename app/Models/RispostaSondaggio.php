<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Risposta a un sondaggio (f.11 del piano "Gestione 2"): votante polimorfico
 * (Socio dall'area soci o Account dal frontend pubblico), un solo voto per
 * sondaggio+votante (unique a livello di schema). Nessun activity log:
 * il voto resta riservato.
 */
class RispostaSondaggio extends Model
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;

    protected $table = 'risposte_sondaggio';

    protected $fillable = [
        'associazione_id',
        'sondaggio_id',
        'votante_type',
        'votante_id',
        'opzione',
    ];

    protected function casts(): array
    {
        return [
            'opzione' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Sondaggio, $this>
     */
    public function sondaggio(): BelongsTo
    {
        return $this->belongsTo(Sondaggio::class, 'sondaggio_id');
    }

    /**
     * Votante: Socio (area soci) o Account (frontend pubblico).
     *
     * @return MorphTo<Model, $this>
     */
    public function votante(): MorphTo
    {
        return $this->morphTo('votante');
    }
}
