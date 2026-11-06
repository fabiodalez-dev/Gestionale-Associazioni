<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Discussione tra soci (issue #13): titolo, autore e messaggi (il primo è il
 * testo di apertura). La moderazione può fissarla in alto o chiuderla alle
 * risposte.
 */
class Discussione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    protected $table = 'discussioni';

    protected $attributes = [
        'fissata' => false,
        'chiusa' => false,
    ];

    protected $fillable = [
        'associazione_id',
        'spazio_id',
        'socio_id',
        'titolo',
        'fissata',
        'chiusa',
        'ultima_attivita_at',
    ];

    protected function casts(): array
    {
        return [
            'fissata' => 'boolean',
            'chiusa' => 'boolean',
            'ultima_attivita_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DiscussioneSpazio, $this>
     */
    public function spazio(): BelongsTo
    {
        return $this->belongsTo(DiscussioneSpazio::class, 'spazio_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function autore(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return HasMany<DiscussioneMessaggio, $this>
     */
    public function messaggi(): HasMany
    {
        return $this->hasMany(DiscussioneMessaggio::class, 'discussione_id');
    }

    /**
     * @return HasMany<DiscussioneIscrizione, $this>
     */
    public function iscrizioni(): HasMany
    {
        return $this->hasMany(DiscussioneIscrizione::class, 'discussione_id');
    }
}
