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
 * Proposta di un socio (issue #10). Ciclo di vita:
 * in_revisione → pubblicata (raccoglie adesioni) → da_esaminare (soglia
 * raggiunta) → in_odg (punto di una riunione) → accolta | respinta.
 * La segreteria può non ammetterla in revisione, con motivazione.
 */
class Proposta extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const STATO_IN_REVISIONE = 'in_revisione';

    public const STATO_PUBBLICATA = 'pubblicata';

    public const STATO_DA_ESAMINARE = 'da_esaminare';

    public const STATO_IN_ODG = 'in_odg';

    public const STATO_ACCOLTA = 'accolta';

    public const STATO_RESPINTA = 'respinta';

    public const STATO_NON_AMMESSA = 'non_ammessa';

    public const STATI = [
        self::STATO_IN_REVISIONE => 'In revisione',
        self::STATO_PUBBLICATA => 'Raccoglie adesioni',
        self::STATO_DA_ESAMINARE => 'Da esaminare',
        self::STATO_IN_ODG => 'All\'ordine del giorno',
        self::STATO_ACCOLTA => 'Accolta',
        self::STATO_RESPINTA => 'Respinta',
        self::STATO_NON_AMMESSA => 'Non ammessa',
    ];

    public const CATEGORIE = [
        'attivita' => 'Nuova attività o iniziativa',
        'regolamento' => 'Modifica di statuto o regolamento',
        'mozione' => 'Mozione per l\'assemblea',
        'altro' => 'Altro',
    ];

    public const DESTINATARI = [
        'direttivo' => 'Consiglio direttivo',
        'assemblea' => 'Assemblea dei soci',
    ];

    protected $table = 'proposte';

    protected $attributes = [
        'stato' => self::STATO_IN_REVISIONE,
    ];

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'titolo',
        'testo',
        'categoria',
        'destinatario',
        'stato',
        'motivazione',
        'riunione_id',
        'punto_indice',
        'pubblicata_at',
        'soglia_raggiunta_at',
        'esito_at',
    ];

    protected function casts(): array
    {
        return [
            'punto_indice' => 'integer',
            'pubblicata_at' => 'datetime',
            'soglia_raggiunta_at' => 'datetime',
            'esito_at' => 'datetime',
        ];
    }

    public function statoLabel(): string
    {
        return self::STATI[$this->stato] ?? $this->stato;
    }

    /** Raccoglie ancora adesioni (pubblicata, anche dopo la soglia, finché non è in OdG). */
    public function accettaAdesioni(): bool
    {
        return in_array($this->stato, [self::STATO_PUBBLICATA, self::STATO_DA_ESAMINARE], true);
    }

    /**
     * Proposte visibili agli altri soci (pubblicate in poi, non le non ammesse).
     *
     * @param  Builder<Proposta>  $query
     * @return Builder<Proposta>
     */
    public function scopePubbliche(Builder $query): Builder
    {
        return $query->whereNotIn('stato', [self::STATO_IN_REVISIONE, self::STATO_NON_AMMESSA]);
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function proponente(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return BelongsTo<Riunione, $this>
     */
    public function riunione(): BelongsTo
    {
        return $this->belongsTo(Riunione::class, 'riunione_id');
    }

    /**
     * @return HasMany<AdesioneProposta, $this>
     */
    public function adesioni(): HasMany
    {
        return $this->hasMany(AdesioneProposta::class, 'proposta_id');
    }
}
