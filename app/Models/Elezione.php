<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Elezione di un organo sociale in assemblea (issue #9). Ciclo di vita:
 * candidature → voto → scrutinio → proclamata. Alla proclamazione le cariche
 * attive dei ruoli dell'organo si chiudono e gli eletti ricevono la carica
 * dell'organo per la durata del mandato.
 */
class Elezione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const STATO_CANDIDATURE = 'candidature';

    public const STATO_VOTO = 'voto';

    public const STATO_SCRUTINIO = 'scrutinio';

    public const STATO_PROCLAMATA = 'proclamata';

    public const STATI = [
        self::STATO_CANDIDATURE => 'Candidature',
        self::STATO_VOTO => 'Voto aperto',
        self::STATO_SCRUTINIO => 'Scrutinio',
        self::STATO_PROCLAMATA => 'Proclamata',
    ];

    /**
     * Organi eleggibili: etichetta, ruolo dato agli eletti e ruoli delle
     * cariche uscenti (chiuse alla proclamazione).
     */
    public const ORGANI = [
        'direttivo' => [
            'label' => 'Consiglio direttivo',
            'ruolo' => 'Consigliere',
            'uscenti' => ['Presidente', 'Vicepresidente', 'Segretario', 'Tesoriere', 'Consigliere'],
        ],
        'revisori' => [
            'label' => 'Organo di controllo / revisori dei conti',
            'ruolo' => 'Revisore dei Conti',
            'uscenti' => ['Revisore dei Conti'],
        ],
        'probiviri' => [
            'label' => 'Collegio dei probiviri',
            'ruolo' => 'Probiviro',
            'uscenti' => ['Probiviro'],
        ],
    ];

    /** Come si risolvono le parità di preferenze. */
    public const REGOLE_PARITA = [
        'anzianita_iscrizione' => 'Prevale chi è iscritto da più tempo',
        'eta' => 'Prevale il più anziano d\'età',
    ];

    protected $table = 'elezioni';

    /** Stessi default delle colonne, già disponibili sul modello appena creato. */
    protected $attributes = [
        'stato' => self::STATO_CANDIDATURE,
        'regola_parita' => 'anzianita_iscrizione',
        'durata_mandato_mesi' => 36,
        'anzianita_minima_mesi' => 0,
        'schede_valide_sala' => 0,
        'schede_bianche_sala' => 0,
        'schede_nulle_sala' => 0,
    ];

    protected $fillable = [
        'associazione_id',
        'riunione_id',
        'organo',
        'posti',
        'max_preferenze',
        'durata_mandato_mesi',
        'candidature_dal',
        'candidature_al',
        'anzianita_minima_mesi',
        'regola_parita',
        'stato',
        'voto_aperto_at',
        'voto_chiuso_at',
        'schede_valide_sala',
        'schede_bianche_sala',
        'schede_nulle_sala',
        'proclamata_at',
    ];

    protected function casts(): array
    {
        return [
            'posti' => 'integer',
            'max_preferenze' => 'integer',
            'durata_mandato_mesi' => 'integer',
            'candidature_dal' => 'date',
            'candidature_al' => 'date',
            'anzianita_minima_mesi' => 'integer',
            'voto_aperto_at' => 'datetime',
            'voto_chiuso_at' => 'datetime',
            'schede_valide_sala' => 'integer',
            'schede_bianche_sala' => 'integer',
            'schede_nulle_sala' => 'integer',
            'proclamata_at' => 'datetime',
        ];
    }

    public function statoLabel(): string
    {
        return self::STATI[$this->stato] ?? $this->stato;
    }

    public function organoLabel(): string
    {
        return self::ORGANI[$this->organo]['label'] ?? $this->organo;
    }

    public function ruoloEletti(): string
    {
        return self::ORGANI[$this->organo]['ruolo'] ?? 'Consigliere';
    }

    /**
     * @return list<string>
     */
    public function ruoliUscenti(): array
    {
        return self::ORGANI[$this->organo]['uscenti'] ?? [];
    }

    /** Le candidature si presentano dall'area soci in questo momento? */
    public function candidatureAperte(): bool
    {
        $oggi = now()->startOfDay();

        return $this->stato === self::STATO_CANDIDATURE
            && ! $this->candidature_dal->isAfter($oggi)
            && ! $this->candidature_al->isBefore($oggi);
    }

    /**
     * @return BelongsTo<Riunione, $this>
     */
    public function riunione(): BelongsTo
    {
        return $this->belongsTo(Riunione::class, 'riunione_id');
    }

    /**
     * @return HasMany<Candidatura, $this>
     */
    public function candidature(): HasMany
    {
        return $this->hasMany(Candidatura::class, 'elezione_id');
    }

    /**
     * @return HasMany<SchedaElezione, $this>
     */
    public function schede(): HasMany
    {
        return $this->hasMany(SchedaElezione::class, 'elezione_id');
    }

    /**
     * @return HasMany<VotanteElezione, $this>
     */
    public function votanti(): HasMany
    {
        return $this->hasMany(VotanteElezione::class, 'elezione_id');
    }
}
