<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Votazione su un punto dell'OdG (f.18 del piano "Organizzazione 3"):
 * esito con conteggio favorevoli/contrari/astenuti, unica per
 * riunione+punto; le approvate alimentano l'archivio Decisioni (f.19).
 *
 * Voto online in assemblea (issue #8): aperta_at/chiusa_at delimitano il
 * periodo in cui i soci votano dall'area soci; i voti sono in voti_riunione
 * (anonimi se segreta) e chi ha votato in votanti_riunione.
 */
class VotazioneRiunione extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const ESITO_APPROVATO = 'approvato';

    public const ESITO_RESPINTO = 'respinto';

    public const ESITO_RINVIATO = 'rinviato';

    public const ESITI = [
        self::ESITO_APPROVATO,
        self::ESITO_RESPINTO,
        self::ESITO_RINVIATO,
    ];

    protected $table = 'votazioni_riunione';

    protected $fillable = [
        'associazione_id',
        'riunione_id',
        'punto_indice',
        'esito',
        'favorevoli',
        'contrari',
        'astenuti',
        'segreta',
        'aperta_at',
        'chiusa_at',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'punto_indice' => 'integer',
            'favorevoli' => 'integer',
            'contrari' => 'integer',
            'astenuti' => 'integer',
            'segreta' => 'boolean',
            'aperta_at' => 'datetime',
            'chiusa_at' => 'datetime',
        ];
    }

    /** Voto online in corso: aperto e non ancora chiuso. */
    public function isAperta(): bool
    {
        return $this->aperta_at !== null && $this->chiusa_at === null;
    }

    /** Votazione nata dal voto online (aperta almeno una volta). */
    public function isOnline(): bool
    {
        return $this->aperta_at !== null;
    }

    /**
     * @return HasMany<VotoRiunione, $this>
     */
    public function voti(): HasMany
    {
        return $this->hasMany(VotoRiunione::class, 'votazione_id');
    }

    /**
     * @return HasMany<VotanteRiunione, $this>
     */
    public function votanti(): HasMany
    {
        return $this->hasMany(VotanteRiunione::class, 'votazione_id');
    }

    public function isApprovata(): bool
    {
        return $this->esito === self::ESITO_APPROVATO;
    }

    /**
     * Titolo del punto OdG votato (da riunioni.odg, f.18).
     */
    public function puntoOdg(): ?string
    {
        $odg = $this->riunione?->odg;

        if (! is_array($odg) || ! isset($odg[$this->punto_indice])) {
            return null;
        }

        $punto = $odg[$this->punto_indice];

        return is_array($punto) ? ($punto['punto'] ?? null) : (string) $punto;
    }

    /**
     * Votazioni approvate (archivio Decisioni, f.19).
     *
     * @param  Builder<VotazioneRiunione>  $query
     * @return Builder<VotazioneRiunione>
     */
    public function scopeApprovate(Builder $query): Builder
    {
        return $query->where('esito', self::ESITO_APPROVATO);
    }

    /**
     * @return BelongsTo<Riunione, $this>
     */
    public function riunione(): BelongsTo
    {
        return $this->belongsTo(Riunione::class, 'riunione_id');
    }
}
