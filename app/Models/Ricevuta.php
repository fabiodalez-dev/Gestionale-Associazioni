<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ricevuta di pagamento quota (f.21 del piano): una sola ricevuta per
 * quota (unique a DB), numerazione progressiva per tenant e anno
 * (unique associazione+anno+numero), es. "12/2026".
 */
class Ricevuta extends Model
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;

    protected $table = 'ricevute';

    protected $fillable = [
        'associazione_id',
        'quota_id',
        'numero',
        'anno',
        'importo',
        'emessa_at',
        // Istantanea all'emissione (issue #20): il PDF non cambia se poi
        // si correggono il socio o la quota
        'intestatario_nome',
        'intestatario_cognome',
        'intestatario_codice_fiscale',
        'numero_socio',
        'tipo_quota',
        'metodo_pagamento',
        'data_pagamento',
        'riferimento_transazione',
    ];

    /**
     * Cast di numero, importo e date, compresa la data di pagamento
     * dell'istantanea.
     */
    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'anno' => 'integer',
            'importo' => 'decimal:2',
            'emessa_at' => 'datetime',
            'data_pagamento' => 'date',
        ];
    }

    /**
     * Numero completo della ricevuta, es. "12/2026".
     */
    public function getNumeroCompletoAttribute(): string
    {
        return $this->numero.'/'.$this->anno;
    }

    /**
     * Vero se la ricevuta ha l'istantanea dell'intestatario salvata
     * all'emissione; le ricevute storiche (emesse prima della colonna)
     * non ce l'hanno e il PDF ricade sui dati correnti di socio e quota.
     */
    public function haIstantanea(): bool
    {
        return $this->intestatario_cognome !== null || $this->intestatario_nome !== null;
    }

    /**
     * Dati stampati sulla ricevuta: l'istantanea se presente, altrimenti
     * i dati correnti di socio e quota (ricevute storiche).
     *
     * @return array{intestatario: string|null, codice_fiscale: string|null, numero_socio: string|null, tipo_quota: string|null, metodo_pagamento: string|null, data_pagamento: CarbonInterface|null, riferimento_transazione: string|null}
     */
    public function datiStampa(?Quota $quota, ?Socio $socio): array
    {
        if ($this->haIstantanea()) {
            return [
                'intestatario' => trim($this->intestatario_nome.' '.$this->intestatario_cognome),
                'codice_fiscale' => $this->intestatario_codice_fiscale,
                'numero_socio' => $this->numero_socio,
                'tipo_quota' => $this->tipo_quota,
                'metodo_pagamento' => $this->metodo_pagamento,
                'data_pagamento' => $this->data_pagamento,
                'riferimento_transazione' => $this->riferimento_transazione,
            ];
        }

        return [
            'intestatario' => $socio !== null ? trim($socio->nome.' '.$socio->cognome) : null,
            'codice_fiscale' => $socio?->codice_fiscale,
            'numero_socio' => $socio?->numero_socio !== null ? (string) $socio->numero_socio : null,
            'tipo_quota' => $quota?->tipo,
            'metodo_pagamento' => $quota?->metodo_pagamento,
            'data_pagamento' => $quota?->data_pagamento,
            'riferimento_transazione' => $quota?->gateway_transaction_id,
        ];
    }

    /**
     * @return BelongsTo<Quota, $this>
     */
    public function quota(): BelongsTo
    {
        return $this->belongsTo(Quota::class, 'quota_id');
    }
}
