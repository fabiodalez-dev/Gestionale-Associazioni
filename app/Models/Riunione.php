<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HaAllegati;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;

/**
 * Riunione di un organo sociale (f.17 del piano "Organizzazione 3"): ordine
 * del giorno json [{punto, descrizione, maggioranza}], partecipanti, votazioni
 * per punto (f.18) e verbale generato dalla bozza (modulo Verbale esistente
 * con numerazione automatica).
 *
 * Organo direttivo: invitati gli intestatari delle cariche attive (round 1).
 * Assemblea dei soci (issue #6): partecipanti = elenco degli aventi diritto
 * calcolato alla data della riunione, quorum costitutivo per prima e seconda
 * convocazione, esiti calcolati dalla maggioranza richiesta da ogni punto.
 * Deleghe (issue #7): limite per delegato ed eventuale divieto per chi
 * ricopre una carica. Voto online dall'area soci ed eventuale
 * partecipazione a distanza (issue #8).
 */
class Riunione extends Model implements HasMedia
{
    use BelongsToAssociazione;
    use HaAllegati;
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

    public const ORGANO_DIRETTIVO = 'direttivo';

    public const ORGANO_ASSEMBLEA_ORDINARIA = 'assemblea_ordinaria';

    public const ORGANO_ASSEMBLEA_STRAORDINARIA = 'assemblea_straordinaria';

    /** Organo => etichetta (anche tipo_riunione del verbale generato). */
    public const ORGANI = [
        self::ORGANO_DIRETTIVO => 'Consiglio Direttivo',
        self::ORGANO_ASSEMBLEA_ORDINARIA => 'Assemblea Ordinaria',
        self::ORGANO_ASSEMBLEA_STRAORDINARIA => 'Assemblea Straordinaria',
    ];

    /** Regole di quorum costitutivo (presenti minimi sugli aventi diritto al voto). */
    public const QUORUM = [
        'qualsiasi' => 'Qualunque numero di presenti',
        'un_terzo' => 'Almeno un terzo',
        'maggioranza_assoluta' => 'Maggioranza assoluta (metà più uno)',
        'due_terzi' => 'Almeno due terzi',
        'tre_quarti' => 'Almeno tre quarti',
    ];

    /** Quorum predefiniti per organo assembleare: [prima, seconda convocazione]. */
    private const QUORUM_PREDEFINITI = [
        self::ORGANO_ASSEMBLEA_ORDINARIA => ['maggioranza_assoluta', 'qualsiasi'],
        self::ORGANO_ASSEMBLEA_STRAORDINARIA => ['tre_quarti', 'maggioranza_assoluta'],
    ];

    /** Maggioranze deliberative ammesse per i punti dell'OdG. */
    public const MAGGIORANZE = [
        'semplice' => 'Semplice (favorevoli più dei contrari)',
        'assoluta' => 'Assoluta (più della metà dei presenti)',
        'due_terzi' => 'Due terzi dei presenti',
    ];

    protected $table = 'riunioni';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'organo',
        'data_ora',
        'seconda_convocazione_at',
        'quorum_prima',
        'quorum_seconda',
        'requisito_quota_pagata',
        'escludi_minorenni_voto',
        'max_deleghe_per_socio',
        'deleghe_vietate_cariche',
        'voto_online',
        'partecipazione_remota',
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
            'seconda_convocazione_at' => 'datetime',
            'odg' => 'array',
            'requisito_quota_pagata' => 'boolean',
            'escludi_minorenni_voto' => 'boolean',
            'max_deleghe_per_socio' => 'integer',
            'deleghe_vietate_cariche' => 'boolean',
            'voto_online' => 'boolean',
            'partecipazione_remota' => 'boolean',
            'aventi_diritto_at' => 'datetime',
            'convocazione_inviata_at' => 'datetime',
        ];
    }

    public function isAssemblea(): bool
    {
        return array_key_exists($this->organo, self::QUORUM_PREDEFINITI);
    }

    public function organoLabel(): string
    {
        return self::ORGANI[$this->organo] ?? self::ORGANI[self::ORGANO_DIRETTIVO];
    }

    /**
     * Regola di quorum effettiva per la convocazione: quella scelta o, se
     * vuota, la predefinita dell'organo. Null per il direttivo.
     */
    public function regolaQuorum(bool $seconda = false): ?string
    {
        if (! $this->isAssemblea()) {
            return null;
        }

        $scelta = $seconda ? $this->quorum_seconda : $this->quorum_prima;

        if (is_string($scelta) && array_key_exists($scelta, self::QUORUM)) {
            return $scelta;
        }

        return self::QUORUM_PREDEFINITI[$this->organo][$seconda ? 1 : 0];
    }

    /**
     * L'assemblea ammette deleghe (limite per delegato maggiore di zero)?
     */
    public function ammetteDeleghe(): bool
    {
        return $this->isAssemblea() && (int) $this->max_deleghe_per_socio > 0;
    }

    /**
     * Maggioranza richiesta dal punto OdG (default semplice).
     */
    public function maggioranzaPunto(int $indice): string
    {
        $punto = is_array($this->odg) ? ($this->odg[$indice] ?? null) : null;
        $maggioranza = is_array($punto) ? ($punto['maggioranza'] ?? null) : null;

        return is_string($maggioranza) && array_key_exists($maggioranza, self::MAGGIORANZE)
            ? $maggioranza
            : 'semplice';
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
     * Conflitti di interesse dichiarati sui punti dell'OdG (issue #11).
     *
     * @return HasMany<ConflittoRiunione, $this>
     */
    public function conflitti(): HasMany
    {
        return $this->hasMany(ConflittoRiunione::class, 'riunione_id');
    }

    /**
     * Elezioni che si tengono nell'assemblea (issue #9).
     *
     * @return HasMany<Elezione, $this>
     */
    public function elezioni(): HasMany
    {
        return $this->hasMany(Elezione::class, 'riunione_id');
    }

    /**
     * Deleghe dell'assemblea (issue #7).
     *
     * @return HasMany<DelegaRiunione, $this>
     */
    public function deleghe(): HasMany
    {
        return $this->hasMany(DelegaRiunione::class, 'riunione_id');
    }

    /**
     * @return HasMany<VotazioneRiunione, $this>
     */
    public function votazioni(): HasMany
    {
        return $this->hasMany(VotazioneRiunione::class, 'riunione_id');
    }
}
