<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Sondaggio ai soci (f.11 del piano "Gestione 2"): domanda con opzioni json,
 * finestra di apertura e visibilità opzionale solo soci. Le risposte
 * referenziano l'indice dell'opzione scelta (RispostaSondaggio).
 */
class Sondaggio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'sondaggi';

    /** @var array<string, mixed> */
    protected $attributes = [
        'solo_soci' => false,
    ];

    protected $fillable = [
        'associazione_id',
        'domanda',
        'opzioni',
        'aperto_dal',
        'aperto_al',
        'solo_soci',
    ];

    protected function casts(): array
    {
        return [
            'opzioni' => 'array',
            'aperto_dal' => 'datetime',
            'aperto_al' => 'datetime',
            'solo_soci' => 'boolean',
        ];
    }

    /**
     * Il sondaggio è votabile ora?
     */
    public function isAperto(): bool
    {
        return $this->aperto_dal !== null
            && $this->aperto_dal->lte(now())
            && ($this->aperto_al === null || $this->aperto_al->gte(now()));
    }

    /**
     * Sondaggi attualmente aperti al voto.
     *
     * @param  Builder<Sondaggio>  $query
     * @return Builder<Sondaggio>
     */
    public function scopeAperti(Builder $query): Builder
    {
        return $query
            ->whereNotNull('aperto_dal')
            ->where('aperto_dal', '<=', now())
            ->where(function (Builder $q) {
                $q->whereNull('aperto_al')->orWhere('aperto_al', '>=', now());
            });
    }

    /**
     * Risultati aggregati: conteggio voti per indice opzione,
     * con zero per le opzioni mai votate.
     *
     * @return array<int, int> [indice opzione => numero voti]
     */
    public function risultati(): array
    {
        $conteggi = $this->risposte()
            ->selectRaw('opzione, COUNT(*) as voti')
            ->groupBy('opzione')
            ->pluck('voti', 'opzione')
            ->all();

        $risultati = [];
        foreach (array_keys((array) $this->opzioni) as $indice) {
            $risultati[(int) $indice] = (int) ($conteggi[$indice] ?? 0);
        }

        return $risultati;
    }

    /**
     * @return HasMany<RispostaSondaggio, $this>
     */
    public function risposte(): HasMany
    {
        return $this->hasMany(RispostaSondaggio::class, 'sondaggio_id');
    }
}
