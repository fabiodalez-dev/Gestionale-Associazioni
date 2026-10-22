<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Models\Concerns\NelPerimetroSedeTramite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Richiesta di modifica dei dati anagrafici (f.19 del piano "Gestione 2"):
 * il socio (area soci) o l'account collegato propone modifiche in json
 * {campo: nuovo_valore}; l'admin vede il diff e approva/rifiuta.
 */
class RichiestaModificaDati extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use NelPerimetroSedeTramite;

    public const STATO_IN_ATTESA = 'in_attesa';

    public const STATO_APPROVATA = 'approvata';

    public const STATO_RIFIUTATA = 'rifiutata';

    protected $table = 'richieste_modifica_dati';

    /** @var array<string, mixed> */
    protected $attributes = [
        'stato' => self::STATO_IN_ATTESA,
    ];

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'proposte',
        'stato',
        'origine_type',
        'origine_id',
        'gestita_da',
        'gestita_at',
    ];

    protected function casts(): array
    {
        return [
            'proposte' => 'array',
            'gestita_at' => 'datetime',
        ];
    }

    public function isInAttesa(): bool
    {
        return $this->stato === self::STATO_IN_ATTESA;
    }

    /**
     * Richieste ancora da gestire (badge in sidebar).
     *
     * @param  Builder<RichiestaModificaDati>  $query
     * @return Builder<RichiestaModificaDati>
     */
    public function scopeInAttesa(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_IN_ATTESA);
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * Chi ha proposto la modifica: Socio (area soci) o Account collegato.
     *
     * @return MorphTo<Model, $this>
     */
    public function origine(): MorphTo
    {
        return $this->morphTo('origine');
    }

    /**
     * Admin che ha approvato/rifiutato la richiesta.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function gestitaDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'gestita_da');
    }
}
