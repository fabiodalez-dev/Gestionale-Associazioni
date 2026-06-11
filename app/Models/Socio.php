<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Scout\Searchable;

/**
 * Socio dell'associazione. Funziona anche da Authenticatable per la guard 'socio'
 * (area soci: login via email + password_hash, NULL = nessun accesso).
 */
class Socio extends Authenticatable
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use Notifiable;
    use Searchable;
    use SoftDeletes;

    public const STATO_ATTIVO = 'Attivo';

    public const STATO_SOSPESO = 'Sospeso';

    public const STATO_RADIATO = 'Radiato';

    public const STATO_DECEDUTO = 'Deceduto';

    public const STATO_TRASFERITO = 'Trasferito';

    public const STATO_IN_ATTESA = 'In Attesa';

    public const STATO_IN_ATTESA_DI_PAGAMENTO = 'In Attesa di Pagamento';

    public const STATI = [
        self::STATO_ATTIVO,
        self::STATO_SOSPESO,
        self::STATO_RADIATO,
        self::STATO_DECEDUTO,
        self::STATO_TRASFERITO,
        self::STATO_IN_ATTESA,
        self::STATO_IN_ATTESA_DI_PAGAMENTO,
    ];

    protected $table = 'soci';

    protected $fillable = [
        'associazione_id',
        'sede_id',
        'numero_socio',
        'nome',
        'cognome',
        'data_nascita',
        'codice_fiscale',
        'email',
        'telefono',
        'indirizzo',
        'citta',
        'provincia',
        'cap',
        'data_iscrizione',
        'stato',
        'note',
        'privacy_consenso',
        'email_opt_out',
        'email_opt_out_token',
        'tipo_socio_id',
        'categoria_socio_id',
        'password_hash',
    ];

    protected $hidden = [
        'password_hash',
        'password_reset_token',
        'rinnovo_token',
        'email_opt_out_token',
        'remember_token',
    ];

    /** @var list<string> attributi esclusi dall'activity log */
    protected array $activityLogExcept = [
        'password_hash',
        'email_opt_out_token',
    ];

    protected function casts(): array
    {
        return [
            'data_nascita' => 'date',
            'data_iscrizione' => 'date',
            'privacy_consenso' => 'boolean',
            'email_opt_out' => 'boolean',
            'password_hash' => 'hashed',
            'password_reset_expires' => 'datetime',
            'rinnovo_token_expires' => 'datetime',
        ];
    }

    /**
     * La colonna password legacy si chiama password_hash.
     */
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    /**
     * La tabella soci non ha la colonna remember_token.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function getNomeCompletoAttribute(): string
    {
        return trim($this->nome.' '.$this->cognome);
    }

    public function isAttivo(): bool
    {
        return $this->stato === self::STATO_ATTIVO;
    }

    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    public function tipoSocio(): BelongsTo
    {
        return $this->belongsTo(TipoSocio::class, 'tipo_socio_id');
    }

    public function categoriaSocio(): BelongsTo
    {
        return $this->belongsTo(CategoriaSocio::class, 'categoria_socio_id');
    }

    public function tessere(): HasMany
    {
        return $this->hasMany(Tessera::class, 'socio_id');
    }

    /**
     * Ultima tessera emessa (per data di emissione) — usata da lista e filtri.
     */
    public function ultimaTessera(): HasOne
    {
        return $this->hasOne(Tessera::class, 'socio_id')->latestOfMany('data_emissione');
    }

    public function quote(): HasMany
    {
        return $this->hasMany(Quota::class, 'socio_id');
    }

    public function documenti(): HasMany
    {
        return $this->hasMany(DocumentoSocio::class, 'socio_id');
    }

    public function valoriCampiPersonalizzati(): HasMany
    {
        return $this->hasMany(ValoreCampoPersonalizzato::class, 'socio_id');
    }

    public function tags(): BelongsToMany
    {
        // Nota: niente withPivot('created_at') — la tabella pivot non ha updated_at
        // e dichiarare la colonna farebbe aggiungere entrambi i timestamp in attach/sync.
        // created_at è valorizzata dal default DB (useCurrent).
        return $this->belongsToMany(Tag::class, 'socio_tags', 'socio_id', 'tag_id')
            ->using(SocioTag::class);
    }

    public function partecipazioni(): HasMany
    {
        return $this->hasMany(EventoPartecipante::class, 'socio_id');
    }

    public function eventi(): BelongsToMany
    {
        return $this->belongsToMany(Evento::class, 'eventi_partecipanti', 'socio_id', 'evento_id')
            ->withPivot(['stato_partecipazione', 'data_conferma', 'note']);
    }

    public function storicoAttivita(): HasMany
    {
        return $this->hasMany(StoricoAttivitaSocio::class, 'socio_id');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'socio_id');
    }

    public function emailInCoda(): HasMany
    {
        return $this->hasMany(EmailQueue::class, 'socio_id');
    }

    /**
     * Ricerca full-text via Laravel Scout (driver database — miglioramento 14).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'nome' => (string) $this->nome,
            'cognome' => (string) $this->cognome,
            'email' => (string) $this->email,
            'codice_fiscale' => (string) $this->codice_fiscale,
            'numero_socio' => (string) $this->numero_socio,
        ];
    }
}
