<?php

namespace App\Models;

use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Associazione extends Model
{
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    /** Iscrizione socio: l'admin approva la richiesta (Socio "In Attesa"). */
    public const MODALITA_PREISCRIZIONE = 'preiscrizione';

    /** Iscrizione socio: Socio "Attivo" e account collegato subito. */
    public const MODALITA_DIRETTA = 'diretta';

    public const MODALITA_ISCRIZIONE = [
        self::MODALITA_PREISCRIZIONE,
        self::MODALITA_DIRETTA,
    ];

    /**
     * Step di default (giorni prima della scadenza tessera) dei promemoria
     * rinnovo multi-step (f.3), usati se promemoria_rinnovo_config è NULL.
     */
    public const PROMEMORIA_RINNOVO_DEFAULT = [30, 15, 3];

    protected $table = 'associazioni';

    /**
     * Default in-memory allineati ai default DB (modelli non ancora
     * ricaricati dal database, es. factory->create()).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'iscrizioni_socio_aperte' => true,
        'modalita_iscrizione' => self::MODALITA_PREISCRIZIONE,
    ];

    protected $fillable = [
        'nome',
        'slug',
        'partita_iva',
        'codice_fiscale',
        'indirizzo',
        'citta',
        'provincia',
        'cap',
        'email',
        'telefono',
        'costo_tessera',
        'logo_url',
        'attiva',
        'frontend_pubblico_attivo',
        'descrizione_pubblica',
        'tipo_scadenza_default',
        'giorni_notifica_scadenza',
        'template_email_scadenza',
        'privacy_policy_html',
        'campi_obbligatori_config',
        'iscrizioni_socio_aperte',
        'modalita_iscrizione',
        'iscrizione_form_config',
        'promemoria_rinnovo_config',
        'auguri_automatici',
    ];

    protected function casts(): array
    {
        return [
            'attiva' => 'boolean',
            'frontend_pubblico_attivo' => 'boolean',
            'costo_tessera' => 'decimal:2',
            'giorni_notifica_scadenza' => 'integer',
            'campi_obbligatori_config' => 'array',
            'iscrizioni_socio_aperte' => 'boolean',
            'iscrizione_form_config' => 'array',
            'promemoria_rinnovo_config' => 'array',
            'auguri_automatici' => 'boolean',
        ];
    }

    /**
     * Step promemoria rinnovo del tenant (giorni prima della scadenza,
     * ordinati decrescenti); default PROMEMORIA_RINNOVO_DEFAULT.
     *
     * @return list<int>
     */
    public function promemoriaRinnovoSteps(): array
    {
        $steps = $this->promemoria_rinnovo_config;

        if (! is_array($steps) || $steps === []) {
            $steps = self::PROMEMORIA_RINNOVO_DEFAULT;
        }

        $steps = array_values(array_unique(array_map('intval', $steps)));
        rsort($steps);

        return $steps;
    }

    public function isIscrizioneDiretta(): bool
    {
        return $this->modalita_iscrizione === self::MODALITA_DIRETTA;
    }

    protected static function booted(): void
    {
        // Slug auto-generato dal nome alla creazione (stesso algoritmo del
        // backfill in migration: Str::slug + suffisso numerico su collisione)
        static::creating(function (self $associazione) {
            if (blank($associazione->slug) && filled($associazione->nome)) {
                $associazione->slug = static::generaSlugUnivoco($associazione->nome);
            }
        });
    }

    /**
     * Slug univoco a partire dal nome (suffisso -2, -3, ... su collisione).
     */
    public static function generaSlugUnivoco(string $nome): string
    {
        $base = Str::slug(Str::limit($nome, 90, ''));

        if ($base === '') {
            $base = 'associazione';
        }

        $slug = $base;
        $i = 2;

        while (static::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    /**
     * @return HasMany<Utente, $this>
     */
    public function utenti(): HasMany
    {
        return $this->hasMany(Utente::class, 'associazione_id');
    }

    /**
     * @return HasMany<Socio, $this>
     */
    public function soci(): HasMany
    {
        return $this->hasMany(Socio::class, 'associazione_id');
    }

    /**
     * @return HasMany<Sede, $this>
     */
    public function sedi(): HasMany
    {
        return $this->hasMany(Sede::class, 'associazione_id');
    }

    /**
     * @return HasMany<TipoSocio, $this>
     */
    public function tipiSocio(): HasMany
    {
        return $this->hasMany(TipoSocio::class, 'associazione_id');
    }

    /**
     * @return HasMany<CategoriaSocio, $this>
     */
    public function categorieSocio(): HasMany
    {
        return $this->hasMany(CategoriaSocio::class, 'associazione_id');
    }

    /**
     * @return HasMany<Tessera, $this>
     */
    public function tessere(): HasMany
    {
        return $this->hasMany(Tessera::class, 'associazione_id');
    }

    /**
     * @return HasMany<TesseraTemplate, $this>
     */
    public function tesseraTemplates(): HasMany
    {
        return $this->hasMany(TesseraTemplate::class, 'associazione_id');
    }

    /**
     * @return HasMany<Quota, $this>
     */
    public function quote(): HasMany
    {
        return $this->hasMany(Quota::class, 'associazione_id');
    }

    /**
     * @return HasMany<Evento, $this>
     */
    public function eventi(): HasMany
    {
        return $this->hasMany(Evento::class, 'associazione_id');
    }

    /**
     * @return HasMany<Documento, $this>
     */
    public function documenti(): HasMany
    {
        return $this->hasMany(Documento::class, 'associazione_id');
    }

    /**
     * @return HasMany<DocumentoSocio, $this>
     */
    public function documentiSocio(): HasMany
    {
        return $this->hasMany(DocumentoSocio::class, 'associazione_id');
    }

    /**
     * @return HasMany<CampoPersonalizzato, $this>
     */
    public function campiPersonalizzati(): HasMany
    {
        return $this->hasMany(CampoPersonalizzato::class, 'associazione_id');
    }

    /**
     * @return HasMany<Tag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'associazione_id');
    }

    /**
     * @return HasMany<GruppoDinamico, $this>
     */
    public function gruppiDinamici(): HasMany
    {
        return $this->hasMany(GruppoDinamico::class, 'associazione_id');
    }

    /**
     * @return HasMany<SavedFilter, $this>
     */
    public function savedFilters(): HasMany
    {
        return $this->hasMany(SavedFilter::class, 'associazione_id');
    }

    /**
     * @return HasMany<Verbale, $this>
     */
    public function verbali(): HasMany
    {
        return $this->hasMany(Verbale::class, 'associazione_id');
    }

    /**
     * @return HasMany<Scadenza, $this>
     */
    public function scadenze(): HasMany
    {
        return $this->hasMany(Scadenza::class, 'associazione_id');
    }

    /**
     * @return HasOne<SmtpSetting, $this>
     */
    public function smtpSetting(): HasOne
    {
        return $this->hasOne(SmtpSetting::class, 'associazione_id');
    }

    /**
     * @return HasOne<PaymentGatewaySetting, $this>
     */
    public function paymentGatewaySetting(): HasOne
    {
        return $this->hasOne(PaymentGatewaySetting::class, 'associazione_id');
    }

    /**
     * @return HasMany<EmailTemplate, $this>
     */
    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class, 'associazione_id');
    }

    /**
     * @return HasMany<EmailQueue, $this>
     */
    public function emailQueue(): HasMany
    {
        return $this->hasMany(EmailQueue::class, 'associazione_id');
    }

    /**
     * @return HasMany<EmailLog, $this>
     */
    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'associazione_id');
    }

    /**
     * @return HasMany<ApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'associazione_id');
    }

    /**
     * @return HasMany<StoricoAttivitaSocio, $this>
     */
    public function storicoAttivitaSoci(): HasMany
    {
        return $this->hasMany(StoricoAttivitaSocio::class, 'associazione_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class, 'associazione_id');
    }

    /**
     * @return HasMany<Prenotazione, $this>
     */
    public function prenotazioni(): HasMany
    {
        return $this->hasMany(Prenotazione::class, 'associazione_id');
    }

    /**
     * @return HasMany<RichiestaIscrizione, $this>
     */
    public function richiesteIscrizione(): HasMany
    {
        return $this->hasMany(RichiestaIscrizione::class, 'associazione_id');
    }

    /**
     * @return HasMany<PromemoriaInviato, $this>
     */
    public function promemoriaInviati(): HasMany
    {
        return $this->hasMany(PromemoriaInviato::class, 'associazione_id');
    }

    /**
     * @return HasMany<FeedbackEvento, $this>
     */
    public function feedbackEventi(): HasMany
    {
        return $this->hasMany(FeedbackEvento::class, 'associazione_id');
    }

    /**
     * @return HasMany<Cessazione, $this>
     */
    public function cessazioni(): HasMany
    {
        return $this->hasMany(Cessazione::class, 'associazione_id');
    }

    /**
     * @return HasMany<Carica, $this>
     */
    public function cariche(): HasMany
    {
        return $this->hasMany(Carica::class, 'associazione_id');
    }

    /**
     * @return HasMany<Ricevuta, $this>
     */
    public function ricevute(): HasMany
    {
        return $this->hasMany(Ricevuta::class, 'associazione_id');
    }

    /**
     * @return HasMany<NotaSocio, $this>
     */
    public function noteSocio(): HasMany
    {
        return $this->hasMany(NotaSocio::class, 'associazione_id');
    }

    /**
     * @return HasMany<AttivitaAdmin, $this>
     */
    public function attivitaAdmin(): HasMany
    {
        return $this->hasMany(AttivitaAdmin::class, 'associazione_id');
    }

    /**
     * @return HasMany<Annuncio, $this>
     */
    public function annunci(): HasMany
    {
        return $this->hasMany(Annuncio::class, 'associazione_id');
    }

    /**
     * @return HasMany<NewsletterInviata, $this>
     */
    public function newsletterInviate(): HasMany
    {
        return $this->hasMany(NewsletterInviata::class, 'associazione_id');
    }

    /**
     * @return HasMany<Sondaggio, $this>
     */
    public function sondaggi(): HasMany
    {
        return $this->hasMany(Sondaggio::class, 'associazione_id');
    }

    /**
     * @return HasMany<CategoriaMovimento, $this>
     */
    public function categorieMovimento(): HasMany
    {
        return $this->hasMany(CategoriaMovimento::class, 'associazione_id');
    }

    /**
     * @return HasMany<Movimento, $this>
     */
    public function movimenti(): HasMany
    {
        return $this->hasMany(Movimento::class, 'associazione_id');
    }

    /**
     * @return HasMany<Donazione, $this>
     */
    public function donazioni(): HasMany
    {
        return $this->hasMany(Donazione::class, 'associazione_id');
    }

    /**
     * @return HasMany<SpesaEvento, $this>
     */
    public function speseEvento(): HasMany
    {
        return $this->hasMany(SpesaEvento::class, 'associazione_id');
    }

    /**
     * @return HasMany<RichiestaModificaDati, $this>
     */
    public function richiesteModificaDati(): HasMany
    {
        return $this->hasMany(RichiestaModificaDati::class, 'associazione_id');
    }

    /**
     * @return HasMany<Nucleo, $this>
     */
    public function nuclei(): HasMany
    {
        return $this->hasMany(Nucleo::class, 'associazione_id');
    }

    /**
     * @return HasMany<Accesso, $this>
     */
    public function accessi(): HasMany
    {
        return $this->hasMany(Accesso::class, 'associazione_id');
    }
}
