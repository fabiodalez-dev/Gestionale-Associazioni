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
 * Partecipante invitato a una riunione (f.17 del piano "Organizzazione 3"):
 * socio intestatario di carica (round 1) oppure utente admin, con snapshot
 * del ruolo al momento dell'invito e stato di presenza. Nelle assemblee
 * (issue #6) è un socio dell'elenco aventi diritto: avente_diritto_voto
 * è false per chi partecipa senza voto (es. minorenni) e presente_at
 * registra l'ora dell'appello.
 */
class RiunionePartecipante extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const STATO_INVITATO = 'invitato';

    public const STATO_CONFERMATO = 'confermato';

    public const STATO_ASSENTE = 'assente';

    public const STATI = [
        self::STATO_INVITATO,
        self::STATO_CONFERMATO,
        self::STATO_ASSENTE,
    ];

    protected $table = 'riunioni_partecipanti';

    protected $fillable = [
        'associazione_id',
        'riunione_id',
        'socio_id',
        'utente_id',
        'carica_ruolo',
        'avente_diritto_voto',
        'stato',
        'presente_at',
    ];

    protected function casts(): array
    {
        return [
            'avente_diritto_voto' => 'boolean',
            'presente_at' => 'datetime',
        ];
    }

    public function isPresente(): bool
    {
        return $this->stato === self::STATO_CONFERMATO;
    }

    /**
     * Nome visualizzato dell'invitato (socio o utente).
     */
    public function nomeCompleto(): string
    {
        return $this->socio->nome_completo
            ?? $this->utente->nome_completo
            ?? '';
    }

    /**
     * @param  Builder<RiunionePartecipante>  $query
     * @return Builder<RiunionePartecipante>
     */
    public function scopeConfermati(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_CONFERMATO);
    }

    /**
     * Partecipanti con diritto di voto (base del quorum in assemblea).
     *
     * @param  Builder<RiunionePartecipante>  $query
     * @return Builder<RiunionePartecipante>
     */
    public function scopeConDirittoDiVoto(Builder $query): Builder
    {
        return $query->where('avente_diritto_voto', true);
    }

    /**
     * @return BelongsTo<Riunione, $this>
     */
    public function riunione(): BelongsTo
    {
        return $this->belongsTo(Riunione::class, 'riunione_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function utente(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'utente_id');
    }
}
