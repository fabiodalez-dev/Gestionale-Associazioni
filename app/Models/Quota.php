<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Models\Concerns\NelPerimetroSedeTramite;
use App\Services\Pagamenti\Pagabile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class Quota extends Model implements Pagabile
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;
    use NelPerimetroSedeTramite;

    public const STATO_PAGATA = 'Pagata';

    public const STATO_DA_PAGARE = 'Da Pagare';

    public const STATO_SCADUTA = 'Scaduta';

    public const STATO_IN_SCADENZA = 'In Scadenza';

    public const STATI = [
        self::STATO_PAGATA,
        self::STATO_DA_PAGARE,
        self::STATO_SCADUTA,
        self::STATO_IN_SCADENZA,
    ];

    protected $table = 'quote';

    protected $fillable = [
        'associazione_id',
        'socio_id',
        'anno',
        'importo',
        'data_scadenza',
        'data_pagamento',
        'stato',
        'tipo',
        'note',
        'metodo_pagamento',
        'gateway',
        'gateway_transaction_id',
        'payment_token',
        'payment_token_expires',
    ];

    protected $hidden = [
        'payment_token',
    ];

    protected function casts(): array
    {
        return [
            'anno' => 'integer',
            'importo' => 'decimal:2',
            'data_scadenza' => 'date',
            'data_pagamento' => 'date',
            'payment_token_expires' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }

    /**
     * Ricevuta di pagamento emessa per la quota (f.21, una sola per quota).
     *
     * @return HasOne<Ricevuta, $this>
     */
    public function ricevuta(): HasOne
    {
        return $this->hasOne(Ricevuta::class, 'quota_id');
    }

    /**
     * Movimento di prima nota generato dal pagamento della quota
     * (f.13 — piano "Gestione 2", hook idempotente del modulo).
     *
     * @return MorphOne<Movimento, $this>
     */
    public function movimento(): MorphOne
    {
        return $this->morphOne(Movimento::class, 'origine');
    }

    public function isPagata(): bool
    {
        return $this->data_pagamento !== null;
    }

    /**
     * Stato calcolato a runtime (la colonna `stato` sul DB è denormalizzata e inaffidabile):
     * Pagata se data_pagamento valorizzata; Scaduta se oltre data_scadenza;
     * In Scadenza entro $giorniPreavviso; altrimenti Da Pagare.
     */
    public function statoCalcolato(int $giorniPreavviso = 30): string
    {
        if ($this->isPagata()) {
            return self::STATO_PAGATA;
        }

        $oggi = now()->startOfDay();

        if ($this->data_scadenza->lt($oggi)) {
            return self::STATO_SCADUTA;
        }

        if ($this->data_scadenza->lte($oggi->copy()->addDays($giorniPreavviso))) {
            return self::STATO_IN_SCADENZA;
        }

        return self::STATO_DA_PAGARE;
    }

    public function hasValidPaymentToken(): bool
    {
        return $this->payment_token !== null
            && $this->payment_token_expires !== null
            && $this->payment_token_expires->isFuture()
            && ! $this->isPagata();
    }

    // ------------------------------------------------------------------
    // Pagabile (checkout Stripe/PayPal)
    // ------------------------------------------------------------------

    public function getPagabileId(): string
    {
        return (string) $this->id;
    }

    public function getPagabileTipo(): string
    {
        return Pagabile::TIPO_QUOTA;
    }

    public function getAssociazioneId(): string
    {
        return (string) $this->associazione_id;
    }

    public function getImporto(): float
    {
        return (float) $this->importo;
    }

    public function getDescrizioneRiga(): string
    {
        return ($this->associazione->nome ?? 'Associazione')
            .' - '.($this->tipo ?: 'Quota')
            ." Anno {$this->anno}";
    }

    public function getEmailPagatore(): ?string
    {
        $email = $this->socio()->withTrashed()->first()?->email;

        return filled($email) ? $email : null;
    }
}
