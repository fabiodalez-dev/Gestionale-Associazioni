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
 * Annuncio in bacheca (f.7 del piano "Gestione 2"): corpo HTML purificato,
 * finestra di pubblicazione, evidenza e visibilità opzionale solo per i soci
 * (area riservata). Alimenta anche le newsletter (f.8).
 */
class Annuncio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'annunci';

    /** @var array<string, mixed> */
    protected $attributes = [
        'in_evidenza' => false,
        'solo_soci' => false,
    ];

    protected $fillable = [
        'associazione_id',
        'titolo',
        'corpo_html',
        'pubblicato_dal',
        'pubblicato_al',
        'in_evidenza',
        'solo_soci',
        'creato_da',
    ];

    protected function casts(): array
    {
        return [
            'pubblicato_dal' => 'datetime',
            'pubblicato_al' => 'datetime',
            'in_evidenza' => 'boolean',
            'solo_soci' => 'boolean',
        ];
    }

    /**
     * L'annuncio è visibile ora? (pubblicato_dal valorizzato e nel passato,
     * pubblicato_al assente o nel futuro)
     */
    public function isPubblicato(): bool
    {
        return $this->pubblicato_dal !== null
            && $this->pubblicato_dal->lte(now())
            && ($this->pubblicato_al === null || $this->pubblicato_al->gte(now()));
    }

    /**
     * Annunci attualmente pubblicati.
     *
     * @param  Builder<Annuncio>  $query
     * @return Builder<Annuncio>
     */
    public function scopePubblicati(Builder $query): Builder
    {
        return $query
            ->whereNotNull('pubblicato_dal')
            ->where('pubblicato_dal', '<=', now())
            ->where(function (Builder $q) {
                $q->whereNull('pubblicato_al')->orWhere('pubblicato_al', '>=', now());
            });
    }

    /**
     * Annunci visibili senza autenticazione (home pubblica /a/{slug}).
     *
     * @param  Builder<Annuncio>  $query
     * @return Builder<Annuncio>
     */
    public function scopePubblici(Builder $query): Builder
    {
        return $query->pubblicati()->where('solo_soci', false);
    }

    /**
     * Admin che ha creato l'annuncio.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function creatoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'creato_da');
    }
}
