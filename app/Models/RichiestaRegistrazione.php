<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Richiesta di iscrizione alla piattaforma inviata da un'associazione.
 * Non appartiene a nessun tenant: la gestisce solo il super admin.
 *
 * @property string $id
 * @property string $stato
 * @property string $nome
 * @property string $email
 * @property string|null $tipo
 * @property string $admin_nome
 * @property string|null $admin_cognome
 * @property string $admin_email
 * @property string $admin_password_hash
 * @property string|null $associazione_id
 * @property Carbon|null $gestita_at
 * @property Carbon $created_at
 */
class RichiestaRegistrazione extends Model
{
    use HasUuids;

    public const STATO_IN_ATTESA = 'in_attesa';

    public const STATO_APPROVATA = 'approvata';

    public const STATO_RIFIUTATA = 'rifiutata';

    /** Forme giuridiche più comuni nel terzo settore */
    public const TIPI = [
        'APS' => 'APS — Associazione di promozione sociale',
        'ASD' => 'ASD — Associazione sportiva dilettantistica',
        'SSD' => 'SSD — Società sportiva dilettantistica',
        'ODV' => 'ODV — Organizzazione di volontariato',
        'ETS' => 'Altro ente del terzo settore',
        'ALTRO' => 'Associazione non riconosciuta / altro',
    ];

    protected $table = 'richieste_registrazione';

    protected $fillable = [
        'stato', 'nome', 'email', 'tipo', 'codice_fiscale', 'partita_iva', 'telefono', 'citta', 'provincia',
        'numero_soci_stimato', 'messaggio', 'admin_nome', 'admin_cognome', 'admin_email', 'admin_password_hash',
        'ip', 'privacy_accettata_at', 'email_verificata_at', 'motivo_rifiuto', 'associazione_id', 'gestita_da', 'gestita_at',
    ];

    protected $hidden = ['admin_password_hash'];

    protected function casts(): array
    {
        return [
            'privacy_accettata_at' => 'datetime',
            'email_verificata_at' => 'datetime',
            'gestita_at' => 'datetime',
            'numero_soci_stimato' => 'integer',
        ];
    }

    /**
     * @param  Builder<RichiestaRegistrazione>  $query
     * @return Builder<RichiestaRegistrazione>
     */
    public function scopeInAttesa(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_IN_ATTESA);
    }

    /**
     * Richieste confermate dall'email dell'amministratore e ancora da esaminare.
     *
     * @param  Builder<RichiestaRegistrazione>  $query
     * @return Builder<RichiestaRegistrazione>
     */
    public function scopeDaEsaminare(Builder $query): Builder
    {
        return $query->where('stato', self::STATO_IN_ATTESA)->whereNotNull('email_verificata_at');
    }

    public function isVerificata(): bool
    {
        return $this->email_verificata_at !== null;
    }

    /**
     * @return BelongsTo<Associazione, $this>
     */
    public function associazione(): BelongsTo
    {
        return $this->belongsTo(Associazione::class, 'associazione_id');
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function gestore(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'gestita_da');
    }

    public function isInAttesa(): bool
    {
        return $this->stato === self::STATO_IN_ATTESA;
    }

    public static function modalita(): string
    {
        $modalita = (string) config('piattaforma.registrazione_associazioni', 'disattivata');

        return in_array($modalita, ['revisione', 'automatica'], true) ? $modalita : 'disattivata';
    }
}
