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
 *
 * Addendum "Convocazioni assemblee": tipo 'convocazione' con data/luogo/
 * ordine del giorno, evento collegato opzionale (conferma presenza dal
 * mini-sito) e invio email ai soci attivi (convocazione_inviata_at).
 */
class Annuncio extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const TIPO_COMUNICAZIONE = 'comunicazione';

    public const TIPO_CONVOCAZIONE = 'convocazione';

    public const TIPI = [
        self::TIPO_COMUNICAZIONE,
        self::TIPO_CONVOCAZIONE,
    ];

    protected $table = 'annunci';

    /** @var array<string, mixed> */
    protected $attributes = [
        'tipo' => self::TIPO_COMUNICAZIONE,
        'in_evidenza' => false,
        'solo_soci' => false,
    ];

    protected $fillable = [
        'associazione_id',
        'titolo',
        'tipo',
        'corpo_html',
        'data_assemblea',
        'luogo_assemblea',
        'ordine_del_giorno',
        'evento_id',
        'pubblicato_dal',
        'pubblicato_al',
        'in_evidenza',
        'solo_soci',
        'creato_da',
    ];

    protected function casts(): array
    {
        return [
            'data_assemblea' => 'datetime',
            'convocazione_inviata_at' => 'datetime',
            'pubblicato_dal' => 'datetime',
            'pubblicato_al' => 'datetime',
            'in_evidenza' => 'boolean',
            'solo_soci' => 'boolean',
        ];
    }

    /** L'annuncio è una convocazione di assemblea? */
    public function isConvocazione(): bool
    {
        return $this->tipo === self::TIPO_CONVOCAZIONE;
    }

    /**
     * Evento collegato alla convocazione (conferma presenza dal mini-sito).
     *
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
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
