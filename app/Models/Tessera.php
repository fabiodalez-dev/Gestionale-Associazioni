<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Tessera extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const STATO_ATTIVA = 'Attiva';

    public const STATO_SCADUTA = 'Scaduta';

    public const STATO_SOSPESA = 'Sospesa';

    public const STATO_ANNULLATA = 'Annullata';

    public const STATI = [
        self::STATO_ATTIVA,
        self::STATO_SCADUTA,
        self::STATO_SOSPESA,
        self::STATO_ANNULLATA,
    ];

    public const TIPO_SCADENZA_SOLARE = 'solare';

    public const TIPO_SCADENZA_ANNUALE = 'annuale';

    protected $table = 'tessere';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'numero_tessera',
        'anno_validita',
        'data_emissione',
        'data_scadenza',
        'tipo_scadenza',
        'stato',
        'qr_code_url',
        'template_tessera',
        'evento_creazione_id',
    ];

    protected function casts(): array
    {
        return [
            'anno_validita' => 'integer',
            'data_emissione' => 'date',
            'data_scadenza' => 'date',
        ];
    }

    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    public function eventoCreazione(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_creazione_id');
    }

    /**
     * Validità effettiva: stato 'Attiva' e non scaduta (lo stato 'Scaduta'
     * sul DB è denormalizzato, la verità è data_scadenza vs oggi).
     */
    public function isValida(): bool
    {
        return $this->stato === self::STATO_ATTIVA
            && $this->data_scadenza !== null
            && $this->data_scadenza->gte(now()->startOfDay());
    }

    /**
     * Scaduta di fatto (a prescindere dallo stato sul DB).
     */
    public function isScadutaEffettiva(): bool
    {
        return $this->data_scadenza !== null
            && $this->data_scadenza->lt(now()->startOfDay());
    }

    /**
     * Stato mostrato in UI: una tessera 'Attiva' ma con data_scadenza
     * superata viene visualizzata come 'Scaduta' (stato derivato, §4.7 spec).
     */
    public function statoVisualizzato(): string
    {
        if ($this->stato === self::STATO_ATTIVA && $this->isScadutaEffettiva()) {
            return self::STATO_SCADUTA;
        }

        return $this->stato;
    }

    /**
     * Tessere effettivamente valide (stato Attiva e non scadute).
     * Definizione unica per lista, filtri e verifica (fix incoerenza legacy §9.2).
     */
    public function scopeValide(Builder $query): Builder
    {
        return $query->where($this->getTable().'.stato', self::STATO_ATTIVA)
            ->whereDate($this->getTable().'.data_scadenza', '>=', now()->toDateString());
    }

    /**
     * Tessere scadute di fatto: stato 'Scaduta' oppure data_scadenza superata.
     */
    public function scopeScaduteEffettive(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where(function (Builder $q) use ($table) {
            $q->where($table.'.stato', self::STATO_SCADUTA)
                ->orWhereDate($table.'.data_scadenza', '<', now()->toDateString());
        });
    }

    /**
     * Tessere in scadenza entro $days giorni (default da associazione, §9.11).
     */
    public function scopeInScadenza(Builder $query, int $days = 30): Builder
    {
        $table = $this->getTable();

        return $query->whereDate($table.'.data_scadenza', '>=', now()->toDateString())
            ->whereDate($table.'.data_scadenza', '<=', now()->addDays($days)->toDateString());
    }
}
