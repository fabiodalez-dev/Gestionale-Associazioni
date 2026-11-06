<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Spazio tematico delle discussioni tra soci (issue #13): aperto a tutti i
 * soci attivi o solo a chi ha un tag (es. un gruppo di lavoro).
 */
class DiscussioneSpazio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const ACCESSI = [
        'tutti' => 'Tutti i soci attivi',
        'tag' => 'Solo i soci con un tag',
    ];

    protected $table = 'discussioni_spazi';

    protected $attributes = [
        'accesso' => 'tutti',
        'ordine' => 0,
        'archiviato' => false,
    ];

    protected $fillable = [
        'associazione_id',
        'nome',
        'descrizione',
        'accesso',
        'tag_id',
        'ordine',
        'archiviato',
    ];

    protected function casts(): array
    {
        return [
            'ordine' => 'integer',
            'archiviato' => 'boolean',
        ];
    }

    /** Il socio (attivo) può entrare nello spazio? */
    public function accessibileA(Socio $socio): bool
    {
        if ($this->archiviato || ! $socio->isAttivo()) {
            return false;
        }

        return $this->accesso !== 'tag'
            || ($this->tag_id !== null && $socio->tags()->whereKey($this->tag_id)->exists());
    }

    /**
     * Spazi non archiviati visibili al socio.
     *
     * @param  Builder<DiscussioneSpazio>  $query
     * @return Builder<DiscussioneSpazio>
     */
    public function scopeAccessibiliA(Builder $query, Socio $socio): Builder
    {
        return $query->where('archiviato', false)->where(fn (Builder $q) => $q
            ->where('accesso', 'tutti')
            ->orWhere(fn (Builder $t) => $t->where('accesso', 'tag')->whereIn('tag_id', $socio->tags()->select('tags.id'))));
    }

    /**
     * @return BelongsTo<Tag, $this>
     */
    public function tag(): BelongsTo
    {
        return $this->belongsTo(Tag::class, 'tag_id');
    }

    /**
     * @return HasMany<Discussione, $this>
     */
    public function discussioni(): HasMany
    {
        return $this->hasMany(Discussione::class, 'spazio_id');
    }
}
