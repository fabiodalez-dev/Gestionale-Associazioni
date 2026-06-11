<?php

namespace App\Models;

use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Associazione extends Model
{
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    protected $table = 'associazioni';

    protected $fillable = [
        'nome',
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
        'tipo_scadenza_default',
        'giorni_notifica_scadenza',
        'template_email_scadenza',
        'privacy_policy_html',
        'campi_obbligatori_config',
    ];

    protected function casts(): array
    {
        return [
            'attiva' => 'boolean',
            'costo_tessera' => 'decimal:2',
            'giorni_notifica_scadenza' => 'integer',
            'campi_obbligatori_config' => 'array',
        ];
    }

    public function utenti(): HasMany
    {
        return $this->hasMany(Utente::class, 'associazione_id');
    }

    public function soci(): HasMany
    {
        return $this->hasMany(Socio::class, 'associazione_id');
    }

    public function sedi(): HasMany
    {
        return $this->hasMany(Sede::class, 'associazione_id');
    }

    public function tipiSocio(): HasMany
    {
        return $this->hasMany(TipoSocio::class, 'associazione_id');
    }

    public function categorieSocio(): HasMany
    {
        return $this->hasMany(CategoriaSocio::class, 'associazione_id');
    }

    public function tessere(): HasMany
    {
        return $this->hasMany(Tessera::class, 'associazione_id');
    }

    public function tesseraTemplates(): HasMany
    {
        return $this->hasMany(TesseraTemplate::class, 'associazione_id');
    }

    public function quote(): HasMany
    {
        return $this->hasMany(Quota::class, 'associazione_id');
    }

    public function eventi(): HasMany
    {
        return $this->hasMany(Evento::class, 'associazione_id');
    }

    public function documenti(): HasMany
    {
        return $this->hasMany(Documento::class, 'associazione_id');
    }

    public function documentiSocio(): HasMany
    {
        return $this->hasMany(DocumentoSocio::class, 'associazione_id');
    }

    public function campiPersonalizzati(): HasMany
    {
        return $this->hasMany(CampoPersonalizzato::class, 'associazione_id');
    }

    public function tags(): HasMany
    {
        return $this->hasMany(Tag::class, 'associazione_id');
    }

    public function gruppiDinamici(): HasMany
    {
        return $this->hasMany(GruppoDinamico::class, 'associazione_id');
    }

    public function savedFilters(): HasMany
    {
        return $this->hasMany(SavedFilter::class, 'associazione_id');
    }

    public function verbali(): HasMany
    {
        return $this->hasMany(Verbale::class, 'associazione_id');
    }

    public function scadenze(): HasMany
    {
        return $this->hasMany(Scadenza::class, 'associazione_id');
    }

    public function smtpSetting(): HasOne
    {
        return $this->hasOne(SmtpSetting::class, 'associazione_id');
    }

    public function paymentGatewaySetting(): HasOne
    {
        return $this->hasOne(PaymentGatewaySetting::class, 'associazione_id');
    }

    public function emailTemplates(): HasMany
    {
        return $this->hasMany(EmailTemplate::class, 'associazione_id');
    }

    public function emailQueue(): HasMany
    {
        return $this->hasMany(EmailQueue::class, 'associazione_id');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(EmailLog::class, 'associazione_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'associazione_id');
    }

    public function storicoAttivitaSoci(): HasMany
    {
        return $this->hasMany(StoricoAttivitaSocio::class, 'associazione_id');
    }
}
