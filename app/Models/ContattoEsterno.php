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
 * Contatto esterno del mini-CRM (f.21 del piano "Organizzazione 3"):
 * fornitori, enti, sponsor, stampa con referente, recapiti e tag liberi.
 */
class ContattoEsterno extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const TIPO_FORNITORE = 'fornitore';

    public const TIPO_ENTE = 'ente';

    public const TIPO_SPONSOR = 'sponsor';

    public const TIPO_STAMPA = 'stampa';

    public const TIPO_ALTRO = 'altro';

    public const TIPI = [
        self::TIPO_FORNITORE,
        self::TIPO_ENTE,
        self::TIPO_SPONSOR,
        self::TIPO_STAMPA,
        self::TIPO_ALTRO,
    ];

    protected $table = 'contatti_esterni';

    protected $fillable = [
        'associazione_id',
        'tipo',
        'nome',
        'referente',
        'email',
        'telefono',
        'note',
        'tag',
    ];

    protected function casts(): array
    {
        return [
            'tag' => 'array',
        ];
    }

    /**
     * @param  Builder<ContattoEsterno>  $query
     * @return Builder<ContattoEsterno>
     */
    public function scopeDelTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }

    /**
     * Sponsorizzazioni collegate alla scheda (f.22).
     *
     * @return HasMany<Sponsor, $this>
     */
    public function sponsorizzazioni(): HasMany
    {
        return $this->hasMany(Sponsor::class, 'contatto_esterno_id');
    }
}
