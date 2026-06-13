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
 * Spazio fisico dell'associazione (f.7 del piano "Organizzazione 3"):
 * sala, locale o area prenotabile internamente (f.8) e collegabile a
 * eventi (f.11), turni e attrezzature.
 */
class Spazio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'spazi';

    protected $fillable = [
        'associazione_id',
        'nome',
        'descrizione',
        'capienza',
        'sede_id',
        'attivo',
    ];

    protected function casts(): array
    {
        return [
            'capienza' => 'integer',
            'attivo' => 'boolean',
        ];
    }

    /**
     * @param  Builder<Spazio>  $query
     * @return Builder<Spazio>
     */
    public function scopeAttivi(Builder $query): Builder
    {
        return $query->where('attivo', true);
    }

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    /**
     * @return HasMany<PrenotazioneSpazio, $this>
     */
    public function prenotazioni(): HasMany
    {
        return $this->hasMany(PrenotazioneSpazio::class, 'spazio_id');
    }

    /**
     * @return HasMany<Evento, $this>
     */
    public function eventi(): HasMany
    {
        return $this->hasMany(Evento::class, 'spazio_id');
    }

    /**
     * @return HasMany<Attrezzatura, $this>
     */
    public function attrezzature(): HasMany
    {
        return $this->hasMany(Attrezzatura::class, 'spazio_id');
    }
}
