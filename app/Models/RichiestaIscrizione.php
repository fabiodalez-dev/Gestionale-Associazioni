<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\NelPerimetroSedeTramite;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Richiesta di iscrizione socio inviata dal form pubblico /a/{slug}/iscriviti.
 *
 * Macchina a stati: in_attesa → approvata | rifiutata.
 * In modalità 'diretta' la richiesta nasce già approvata (gestita_da null:
 * nessun intervento admin). dati_form è lo snapshot JSON di tutti i valori
 * inviati; socio_id punta al preiscritto creato dal FormIscrizioneService
 * (null se il socio viene eliminato al rifiuto).
 */
class RichiestaIscrizione extends Model
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;
    use NelPerimetroSedeTramite;

    public const STATO_IN_ATTESA = 'in_attesa';

    public const STATO_APPROVATA = 'approvata';

    public const STATO_RIFIUTATA = 'rifiutata';

    public const STATI = [
        self::STATO_IN_ATTESA,
        self::STATO_APPROVATA,
        self::STATO_RIFIUTATA,
    ];

    protected $table = 'richieste_iscrizione';

    protected $fillable = [
        'associazione_id',
        'account_id',
        'socio_id',
        'stato',
        'dati_form',
        'motivo_rifiuto',
        'gestita_da',
        'gestita_at',
        'delibera_numero',
        'delibera_data',
        'referral_codice',
    ];

    protected function casts(): array
    {
        return [
            'dati_form' => 'array',
            'gestita_at' => 'datetime',
            'delibera_data' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * Il socio preiscritto creato dal form (null dopo un rifiuto con eliminazione).
     *
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * Admin che ha gestito la richiesta (approvazione/rifiuto).
     *
     * @return BelongsTo<Utente, $this>
     */
    /**
     * Socio "ambasciatore" che ha invitato il richiedente con il proprio
     * codice referral (f.23 — piano "Organizzazione 3").
     *
     * @return BelongsTo<Socio, $this>
     */
    public function socioReferente(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'referral_codice', 'codice_referral');
    }

    public function gestore(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'gestita_da');
    }

    /**
     * @param  Builder<RichiestaIscrizione>  $query
     * @return Builder<RichiestaIscrizione>
     */
    public function scopeInAttesa(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_IN_ATTESA);
    }

    public function isInAttesa(): bool
    {
        return $this->stato === self::STATO_IN_ATTESA;
    }

    public function isApprovata(): bool
    {
        return $this->stato === self::STATO_APPROVATA;
    }

    public function isRifiutata(): bool
    {
        return $this->stato === self::STATO_RIFIUTATA;
    }
}
