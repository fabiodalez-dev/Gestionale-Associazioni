<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evento extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    public const STATO_BOZZA = 'bozza';

    public const STATO_PUBBLICATO = 'pubblicato';

    public const STATO_ARCHIVIATO = 'archiviato';

    public const STATI_PUBBLICAZIONE = [
        self::STATO_BOZZA,
        self::STATO_PUBBLICATO,
        self::STATO_ARCHIVIATO,
    ];

    protected $table = 'eventi';

    /**
     * Default in-memory allineati ai default DB (modelli non ancora
     * ricaricati dal database, es. factory->create()).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'iscrizioni_aperte' => true,
        'richiede_approvazione' => false,
        'solo_soci' => false,
    ];

    protected $fillable = [
        'associazione_id',
        'titolo',
        'descrizione',
        'data_evento',
        'data_fine',
        'luogo',
        'stato_pubblicazione',
        'capienza',
        'prezzo',
        'budget',
        'iscrizioni_dal',
        'iscrizioni_al',
        'iscrizioni_aperte',
        'richiede_approvazione',
        'max_posti_per_prenotazione',
        'lista_attesa_attiva',
        'annullamento_entro_ore',
        'immagine_url',
        'serie_id',
        'solo_soci',
    ];

    protected function casts(): array
    {
        return [
            'data_evento' => 'datetime',
            'data_fine' => 'datetime',
            'capienza' => 'integer',
            'prezzo' => 'decimal:2',
            'budget' => 'decimal:2',
            'iscrizioni_dal' => 'datetime',
            'iscrizioni_al' => 'datetime',
            'iscrizioni_aperte' => 'boolean',
            'richiede_approvazione' => 'boolean',
            'max_posti_per_prenotazione' => 'integer',
            'lista_attesa_attiva' => 'boolean',
            'annullamento_entro_ore' => 'integer',
            'solo_soci' => 'boolean',
        ];
    }

    /**
     * L'evento fa parte di una serie ricorrente (f.8)?
     */
    public function isInSerie(): bool
    {
        return $this->serie_id !== null;
    }

    /**
     * Evento riservato ai soci attivi (f.10)?
     */
    public function isRiservatoAiSoci(): bool
    {
        return (bool) $this->solo_soci;
    }

    /**
     * Le altre occorrenze della stessa serie ricorrente (f.8).
     *
     * @param  Builder<Evento>  $query
     * @return Builder<Evento>
     */
    public function scopeDellaSerie(Builder $query, string $serieId): Builder
    {
        return $query->where('serie_id', $serieId);
    }

    /**
     * Solo eventi visibili sul frontend pubblico.
     *
     * @param  Builder<Evento>  $query
     * @return Builder<Evento>
     */
    public function scopePubblicati(Builder $query): Builder
    {
        return $query->where('stato_pubblicazione', self::STATO_PUBBLICATO);
    }

    /**
     * Posti ancora disponibili (null = capienza illimitata).
     *
     * SOLA LETTURA (liste, dettaglio evento): per decidere se accettare una
     * prenotazione il conteggio va rifatto dentro DB::transaction con
     * lockForUpdate() sulla riga eventi (vedi PrenotazioneService).
     */
    public function postiDisponibili(): ?int
    {
        if ($this->capienza === null) {
            return null;
        }

        return max(0, $this->capienza - Prenotazione::postiOccupati($this->id));
    }

    /**
     * L'evento accetta prenotazioni dal frontend pubblico?
     * (Pubblicato, non ancora iniziato, iscrizioni non chiuse manualmente,
     * finestra iscrizioni aperta.)
     */
    public function isPrenotabile(): bool
    {
        if ($this->stato_pubblicazione !== self::STATO_PUBBLICATO) {
            return false;
        }

        if ($this->data_evento->isPast()) {
            return false;
        }

        if (! $this->iscrizioni_aperte) {
            return false;
        }

        if ($this->iscrizioni_dal !== null && $this->iscrizioni_dal->isFuture()) {
            return false;
        }

        if ($this->iscrizioni_al !== null && $this->iscrizioni_al->isPast()) {
            return false;
        }

        return true;
    }

    public function isAPagamento(): bool
    {
        return (float) $this->prezzo > 0;
    }

    /**
     * @return HasMany<Prenotazione, $this>
     */
    public function prenotazioni(): HasMany
    {
        return $this->hasMany(Prenotazione::class, 'evento_id');
    }

    /**
     * @return HasMany<EventoPartecipante, $this>
     */
    public function partecipanti(): HasMany
    {
        return $this->hasMany(EventoPartecipante::class, 'evento_id');
    }

    /**
     * @return BelongsToMany<Socio, $this>
     */
    public function soci(): BelongsToMany
    {
        return $this->belongsToMany(Socio::class, 'eventi_partecipanti', 'evento_id', 'socio_id')
            ->withPivot(['stato_partecipazione', 'data_conferma', 'note']);
    }

    /**
     * @return HasMany<Tessera, $this>
     */
    public function tessereCreate(): HasMany
    {
        return $this->hasMany(Tessera::class, 'evento_creazione_id');
    }

    /**
     * Feedback post-evento dei partecipanti (f.11).
     *
     * @return HasMany<FeedbackEvento, $this>
     */
    public function feedback(): HasMany
    {
        return $this->hasMany(FeedbackEvento::class, 'evento_id');
    }

    /**
     * Verbali collegati all'evento (es. assemblea, f.17).
     *
     * @return HasMany<Verbale, $this>
     */
    public function verbali(): HasMany
    {
        return $this->hasMany(Verbale::class, 'evento_id');
    }

    /**
     * Spese dell'evento (f.17 — piano "Gestione 2"): confrontate con
     * budget e incassi delle prenotazioni pagate.
     *
     * @return HasMany<SpesaEvento, $this>
     */
    public function spese(): HasMany
    {
        return $this->hasMany(SpesaEvento::class, 'evento_id');
    }
}
