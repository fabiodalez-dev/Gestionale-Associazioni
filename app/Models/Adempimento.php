<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adempimento ricorrente annuale (f.20 del piano "Organizzazione 3"):
 * scadenza in un mese dell'anno (1-12) con checklist per anno
 * (adempimenti_anni) mostrata nello Scadenzario esistente.
 */
class Adempimento extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    /** Seed iniziale suggerito per i nuovi tenant (f.20). */
    public const SUGGERITI = [
        ['titolo' => 'Rinnovo assicurazione volontari', 'mese_scadenza' => 1],
        ['titolo' => 'Approvazione bilancio annuale', 'mese_scadenza' => 4],
        ['titolo' => 'Rinnovo cariche sociali', 'mese_scadenza' => 5],
        ['titolo' => 'Aggiornamento libro soci', 'mese_scadenza' => 12],
    ];

    protected $table = 'adempimenti';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'descrizione',
        'manuale_pagina_id',
        'mese_scadenza',
        'attivo',
    ];

    protected function casts(): array
    {
        return [
            'mese_scadenza' => 'integer',
            'attivo' => 'boolean',
        ];
    }

    /**
     * L'adempimento risulta completato per l'anno indicato?
     */
    public function isCompletatoPerAnno(int $anno): bool
    {
        return $this->anni()
            ->where('anno', $anno)
            ->whereNotNull('completato_at')
            ->exists();
    }

    /**
     * @param  Builder<Adempimento>  $query
     * @return Builder<Adempimento>
     */
    public function scopeAttivi(Builder $query): Builder
    {
        return $query->where('attivo', true);
    }

    /**
     * Adempimenti attivi in scadenza nel mese indicato (promemoria mensile, f.20).
     *
     * @param  Builder<Adempimento>  $query
     * @return Builder<Adempimento>
     */
    public function scopeDelMese(Builder $query, int $mese): Builder
    {
        return $query->attivi()->where('mese_scadenza', $mese);
    }

    /**
     * @return HasMany<AdempimentoAnno, $this>
     */
    public function anni(): HasMany
    {
        return $this->hasMany(AdempimentoAnno::class, 'adempimento_id');
    }

    /**
     * Procedura del manuale operativo da seguire (issue #12).
     *
     * @return BelongsTo<ManualePagina, $this>
     */
    public function paginaManuale(): BelongsTo
    {
        return $this->belongsTo(ManualePagina::class, 'manuale_pagina_id');
    }
}
