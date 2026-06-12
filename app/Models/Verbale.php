<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Verbale extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;
    use SoftDeletes;

    public const TIPI_RIUNIONE = [
        'Assemblea Ordinaria',
        'Assemblea Straordinaria',
        'Consiglio Direttivo',
        'Commissione',
    ];

    protected $table = 'verbali';

    protected $fillable = [
        'associazione_id',
        'titolo',
        'numero',
        'anno',
        'data_riunione',
        'tipo_riunione',
        'presenti',
        'ordine_del_giorno',
        'deliberazioni',
        'allegati',
        'approvato',
        'approvato_da',
        'data_approvazione',
        'evento_id',
    ];

    protected function casts(): array
    {
        return [
            'numero' => 'integer',
            'anno' => 'integer',
            'data_riunione' => 'date',
            'approvato' => 'boolean',
            'data_approvazione' => 'date',
        ];
    }

    /**
     * Numero di protocollo per anno, es. "3/2026" (f.17);
     * null se il verbale non è ancora numerato.
     */
    public function getNumeroCompletoAttribute(): ?string
    {
        if ($this->numero === null || $this->anno === null) {
            return null;
        }

        return $this->numero.'/'.$this->anno;
    }

    /**
     * Prossimo numero progressivo per l'anno nel tenant corrente (f.17).
     * Da chiamare dentro una transaction in caso di scritture concorrenti
     * (unique a DB su associazione+anno+numero come rete di sicurezza).
     */
    public static function prossimoNumero(int $anno): int
    {
        return (int) static::query()->where('anno', $anno)->max('numero') + 1;
    }

    /**
     * @return BelongsTo<Utente, $this>
     */
    public function approvatoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'approvato_da');
    }

    /**
     * Evento collegato (es. assemblea, f.17).
     *
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    /**
     * @return HasMany<VerbaleFirmatario, $this>
     */
    public function firmatari(): HasMany
    {
        return $this->hasMany(VerbaleFirmatario::class, 'verbale_id');
    }

    /**
     * Azzera lo stato di approvazione (usato alla modifica di un verbale
     * approvato: irrigidimento consigliato dalla spec §6).
     */
    public function azzeraApprovazione(): void
    {
        $this->approvato = false;
        $this->approvato_da = null;
        $this->data_approvazione = null;
    }
}
