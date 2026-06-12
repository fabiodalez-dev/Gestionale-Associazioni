<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Attività/promemoria admin (f.4 del piano "Gestione 2"): titolo, scadenza,
 * assegnatario e collegamento polimorfico opzionale a un Socio o a un Evento.
 * Alimenta il widget "Le mie attività" in dashboard e il promemoria email
 * del giorno della scadenza (comando schedulato di Rifiniture).
 */
class AttivitaAdmin extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const STATO_APERTA = 'aperta';

    public const STATO_COMPLETATA = 'completata';

    public const STATI = [
        self::STATO_APERTA,
        self::STATO_COMPLETATA,
    ];

    protected $table = 'attivita_admin';

    /** @var array<string, mixed> */
    protected $attributes = [
        'stato' => self::STATO_APERTA,
    ];

    protected $fillable = [
        'associazione_id',
        'titolo',
        'descrizione',
        'scadenza',
        'assegnatario_id',
        'notabile_type',
        'notabile_id',
        'stato',
        'completata_at',
    ];

    protected function casts(): array
    {
        return [
            'scadenza' => 'date',
            'completata_at' => 'datetime',
        ];
    }

    public function isCompletata(): bool
    {
        return $this->stato === self::STATO_COMPLETATA;
    }

    /**
     * Attività ancora da fare.
     *
     * @param  Builder<AttivitaAdmin>  $query
     * @return Builder<AttivitaAdmin>
     */
    public function scopeAperte(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_APERTA);
    }

    /**
     * Attività aperte con scadenza il giorno indicato (promemoria email f.4).
     *
     * @param  Builder<AttivitaAdmin>  $query
     * @return Builder<AttivitaAdmin>
     */
    public function scopeInScadenzaIl(Builder $query, \DateTimeInterface|string $giorno): Builder
    {
        return $query->aperte()->whereDate('scadenza', $giorno);
    }

    /**
     * Utente assegnatario dell'attività.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function assegnatario(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'assegnatario_id');
    }

    /**
     * Collegamento opzionale a Socio o Evento.
     *
     * @return MorphTo<Model, $this>
     */
    public function notabile(): MorphTo
    {
        return $this->morphTo('notabile');
    }
}
