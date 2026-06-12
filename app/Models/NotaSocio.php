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
 * Nota interna sul socio (f.3 del piano "Gestione 2"): appunto manuale
 * dell'admin nella scheda socio, distinto dallo storico automatico,
 * con pin opzionale (fissata in cima alla timeline).
 */
class NotaSocio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'note_socio';

    /** @var array<string, mixed> */
    protected $attributes = [
        'fissata' => false,
    ];

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'autore_id',
        'testo',
        'fissata',
    ];

    protected function casts(): array
    {
        return [
            'fissata' => 'boolean',
        ];
    }

    /**
     * Note fissate in cima (pin).
     *
     * @param  Builder<NotaSocio>  $query
     * @return Builder<NotaSocio>
     */
    public function scopeFissate(Builder $query): Builder
    {
        return $query->where('fissata', true);
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * Admin autore della nota.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function autore(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'autore_id');
    }
}
