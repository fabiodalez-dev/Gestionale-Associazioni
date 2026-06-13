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
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
        'nucleo_id',
        'tutore_nome',
        'tutore_telefono',
        'tutore_email',
        'disponibilita',
        'volontario_attivo',
        'codice_referral',
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
            'disponibilita' => 'array',
            'volontario_attivo' => 'boolean',
            'password_hash' => 'hashed',
            'password_reset_expires' => 'datetime',
            'rinnovo_token_expires' => 'datetime',
        ];
    }

    /**
     * Genera automaticamente il codice referral personale alla creazione
     * (f.23 — piano "Organizzazione 3", "porta un amico").
     */
    protected static function booted(): void
    {
        static::creating(function (self $socio) {
            if (empty($socio->codice_referral)) {
                $socio->codice_referral = self::generaCodiceReferral();
            }
        });
    }

    /**
     * Slug univoco (globale) usato nel link di invito /a/{slug}/iscriviti?ref=CODICE.
     */
    public static function generaCodiceReferral(): string
    {
        do {
            $codice = strtolower(Str::random(10));
        } while (self::allTenants()->withTrashed()->where('codice_referral', $codice)->exists());

        return $codice;
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

    /**
     * @return BelongsTo<Sede, $this>
     */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    /**
     * @return BelongsTo<TipoSocio, $this>
     */
    public function tipoSocio(): BelongsTo
    {
        return $this->belongsTo(TipoSocio::class, 'tipo_socio_id');
    }

    /**
     * @return BelongsTo<CategoriaSocio, $this>
     */
    public function categoriaSocio(): BelongsTo
    {
        return $this->belongsTo(CategoriaSocio::class, 'categoria_socio_id');
    }

    /**
     * @return HasMany<Tessera, $this>
     */
    public function tessere(): HasMany
    {
        return $this->hasMany(Tessera::class, 'socio_id');
    }

    /**
     * Ultima tessera emessa (per data di emissione) — usata da lista e filtri.
     *
     * @return HasOne<Tessera, $this>
     */
    public function ultimaTessera(): HasOne
    {
        return $this->hasOne(Tessera::class, 'socio_id')->latestOfMany('data_emissione');
    }

    /**
     * @return HasMany<Quota, $this>
     */
    public function quote(): HasMany
    {
        return $this->hasMany(Quota::class, 'socio_id');
    }

    /**
     * @return HasMany<DocumentoSocio, $this>
     */
    public function documenti(): HasMany
    {
        return $this->hasMany(DocumentoSocio::class, 'socio_id');
    }

    /**
     * @return HasMany<ValoreCampoPersonalizzato, $this>
     */
    public function valoriCampiPersonalizzati(): HasMany
    {
        return $this->hasMany(ValoreCampoPersonalizzato::class, 'socio_id');
    }

    /**
     * @return BelongsToMany<Tag, $this, SocioTag>
     */
    public function tags(): BelongsToMany
    {
        // Nota: niente withPivot('created_at') — la tabella pivot non ha updated_at
        // e dichiarare la colonna farebbe aggiungere entrambi i timestamp in attach/sync.
        // created_at è valorizzata dal default DB (useCurrent).
        return $this->belongsToMany(Tag::class, 'socio_tags', 'socio_id', 'tag_id')
            ->using(SocioTag::class);
    }

    /**
     * @return HasMany<EventoPartecipante, $this>
     */
    public function partecipazioni(): HasMany
    {
        return $this->hasMany(EventoPartecipante::class, 'socio_id');
    }

    /**
     * @return BelongsToMany<Evento, $this>
     */
    public function eventi(): BelongsToMany
    {
        return $this->belongsToMany(Evento::class, 'eventi_partecipanti', 'socio_id', 'evento_id')
            ->withPivot(['stato_partecipazione', 'data_conferma', 'note']);
    }

    /**
     * @return HasMany<StoricoAttivitaSocio, $this>
     */
    public function storicoAttivita(): HasMany
    {
        return $this->hasMany(StoricoAttivitaSocio::class, 'socio_id');
    }

    /**
     * Cessazioni formali registrate per il socio (f.15).
     *
     * @return HasMany<Cessazione, $this>
     */
    public function cessazioni(): HasMany
    {
        return $this->hasMany(Cessazione::class, 'socio_id');
    }

    /**
     * Cariche negli organi sociali (f.16).
     *
     * @return HasMany<Carica, $this>
     */
    public function cariche(): HasMany
    {
        return $this->hasMany(Carica::class, 'socio_id');
    }

    /**
     * Mansioni/competenze del volontario (f.2 — piano "Organizzazione 3").
     *
     * @return BelongsToMany<Mansione, $this>
     */
    public function mansioni(): BelongsToMany
    {
        return $this->belongsToMany(Mansione::class, 'socio_mansioni', 'socio_id', 'mansione_id')
            ->withTimestamps();
    }

    /**
     * Assegnazioni ai turni di volontariato (f.3 — piano "Organizzazione 3").
     *
     * @return HasMany<TurnoAssegnazione, $this>
     */
    public function turniAssegnazioni(): HasMany
    {
        return $this->hasMany(TurnoAssegnazione::class, 'socio_id');
    }

    /**
     * Prestiti di attrezzature (f.10 — piano "Organizzazione 3").
     *
     * @return HasMany<PrestitoAttrezzatura, $this>
     */
    public function prestitiAttrezzature(): HasMany
    {
        return $this->hasMany(PrestitoAttrezzatura::class, 'socio_id');
    }

    /**
     * Richieste di iscrizione arrivate con il codice referral del socio
     * (classifica "ambasciatori", f.23 — piano "Organizzazione 3").
     *
     * @return HasMany<RichiestaIscrizione, $this>
     */
    public function iscrizioniReferral(): HasMany
    {
        return $this->hasMany(RichiestaIscrizione::class, 'referral_codice', 'codice_referral');
    }

    /**
     * Promemoria inviati al socio (rinnovi, documenti — f.3/f.23).
     *
     * @return HasMany<PromemoriaInviato, $this>
     */
    public function promemoriaInviati(): HasMany
    {
        return $this->hasMany(PromemoriaInviato::class, 'socio_id');
    }

    /**
     * @return HasMany<EmailLog, $this>
     */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'socio_id');
    }

    /**
     * @return HasMany<EmailQueue, $this>
     */
    public function emailInCoda(): HasMany
    {
        return $this->hasMany(EmailQueue::class, 'socio_id');
    }

    /**
     * Nucleo familiare di appartenenza (f.20 — piano "Gestione 2").
     *
     * @return BelongsTo<Nucleo, $this>
     */
    public function nucleo(): BelongsTo
    {
        return $this->belongsTo(Nucleo::class, 'nucleo_id');
    }

    /**
     * Note interne degli admin sulla scheda socio (f.3 — piano "Gestione 2").
     * Nome distinto dalla colonna `note` (campo testo legacy).
     *
     * @return HasMany<NotaSocio, $this>
     */
    public function noteInterne(): HasMany
    {
        return $this->hasMany(NotaSocio::class, 'socio_id');
    }

    /**
     * Richieste di modifica dei dati anagrafici (f.19 — piano "Gestione 2").
     *
     * @return HasMany<RichiestaModificaDati, $this>
     */
    public function richiesteModificaDati(): HasMany
    {
        return $this->hasMany(RichiestaModificaDati::class, 'socio_id');
    }

    /**
     * Il socio è minorenne alla data indicata (default: oggi)? (f.22)
     * False se la data di nascita non è nota.
     */
    public function isMinorenne(\DateTimeInterface|string|null $allaData = null): bool
    {
        if ($this->data_nascita === null) {
            return false;
        }

        $riferimento = $allaData !== null
            ? Carbon::parse($allaData)
            : now();

        return $this->data_nascita->diffInYears($riferimento) < 18;
    }

    /**
     * Il tutore è valorizzato (soci minorenni, f.22)?
     */
    public function hasTutore(): bool
    {
        return filled($this->tutore_nome);
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
