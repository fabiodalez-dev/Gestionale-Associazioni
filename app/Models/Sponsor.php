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
 * Sponsor/partner (f.22 del piano "Organizzazione 3"): livello, periodo di
 * validità e visibilità nella sezione "I nostri sostenitori" della home
 * pubblica del mini-sito (solo visibili e nel periodo).
 */
class Sponsor extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const LIVELLO_ORO = 'oro';

    public const LIVELLO_ARGENTO = 'argento';

    public const LIVELLO_BRONZO = 'bronzo';

    public const LIVELLO_PARTNER = 'partner';

    public const LIVELLI = [
        self::LIVELLO_ORO,
        self::LIVELLO_ARGENTO,
        self::LIVELLO_BRONZO,
        self::LIVELLO_PARTNER,
    ];

    protected $table = 'sponsor';

    protected $fillable = [
        'associazione_id',
        'contatto_esterno_id',
        'nome',
        'logo_url',
        'livello',
        'dal',
        'al',
        'visibile_sul_sito',
        'sito_url',
    ];

    protected function casts(): array
    {
        return [
            'dal' => 'date',
            'al' => 'date',
            'visibile_sul_sito' => 'boolean',
        ];
    }

    /**
     * Lo sponsor è nel periodo di validità (estremi NULL = senza limite)?
     */
    public function isNelPeriodo(): bool
    {
        $oggi = now()->startOfDay();

        return ($this->dal === null || ! $this->dal->gt($oggi))
            && ($this->al === null || ! $this->al->lt($oggi));
    }

    /**
     * Sponsor mostrati sulla home pubblica: visibili e nel periodo (f.22).
     *
     * @param  Builder<Sponsor>  $query
     * @return Builder<Sponsor>
     */
    public function scopeVisibiliSulSito(Builder $query): Builder
    {
        $oggi = now()->toDateString();

        return $query->where('visibile_sul_sito', true)
            ->where(function (Builder $q) use ($oggi) {
                $q->whereNull('dal')->orWhereDate('dal', '<=', $oggi);
            })
            ->where(function (Builder $q) use ($oggi) {
                $q->whereNull('al')->orWhereDate('al', '>=', $oggi);
            });
    }

    /**
     * @return BelongsTo<ContattoEsterno, $this>
     */
    public function contattoEsterno(): BelongsTo
    {
        return $this->belongsTo(ContattoEsterno::class, 'contatto_esterno_id');
    }
}
