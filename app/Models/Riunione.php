<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HaAllegati;
use Spatie\MediaLibrary\HasMedia;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Riunione del direttivo (f.17 del piano "Organizzazione 3"): ordine del
 * giorno json [{punto, descrizione}], inviti agli intestatari delle cariche
 * (round 1), votazioni per punto (f.18) e verbale generato dalla bozza
 * (modulo Verbale esistente con numerazione automatica).
 */
class Riunione extends Model implements HasMedia
{
    use HaAllegati;
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const STATO_PIANIFICATA = 'pianificata';

    public const STATO_SVOLTA = 'svolta';

    public const STATO_ANNULLATA = 'annullata';

    public const STATI = [
        self::STATO_PIANIFICATA,
        self::STATO_SVOLTA,
        self::STATO_ANNULLATA,
    ];

    protected $table = 'riunioni';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'data_ora',
        'luogo',
        'spazio_id',
        'odg',
        'stato',
        'verbale_id',
        'convocazione_inviata_at',
    ];

    protected function casts(): array
    {
        return [
            'data_ora' => 'datetime',
            'odg' => 'array',
            'convocazione_inviata_at' => 'datetime',
        ];
    }

    public function isSvolta(): bool
    {
        return $this->stato === self::STATO_SVOLTA;
    }

    /**
     * @param  Builder<Riunione>  $query
     * @return Builder<Riunione>
     */
    public function scopePianificate(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_PIANIFICATA);
    }

    /**
     * @return BelongsTo<Spazio, $this>
     */
    public function spazio(): BelongsTo
    {
        return $this->belongsTo(Spazio::class, 'spazio_id');
    }

    /**
     * Verbale generato dalla riunione (f.18).
     *
     * @return BelongsTo<Verbale, $this>
     */
    public function verbale(): BelongsTo
    {
        return $this->belongsTo(Verbale::class, 'verbale_id');
    }

    /**
     * @return HasMany<RiunionePartecipante, $this>
     */
    public function partecipanti(): HasMany
    {
        return $this->hasMany(RiunionePartecipante::class, 'riunione_id');
    }

    /**
     * @return HasMany<VotazioneRiunione, $this>
     */
    public function votazioni(): HasMany
    {
        return $this->hasMany(VotazioneRiunione::class, 'riunione_id');
    }
}
