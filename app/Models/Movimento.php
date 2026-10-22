<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use App\Models\Concerns\NelPerimetroSede;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Movimento di prima nota (f.13 del piano "Gestione 2"): entrata o uscita
 * con categoria, data e collegamento polimorfico opzionale all'origine
 * (Quota, Prenotazione o Donazione). L'unique su origine garantisce
 * l'idempotenza dei movimenti generati automaticamente post-pagamento.
 */
class Movimento extends Model
{
    use NelPerimetroSede;

    /** Sede vuota = di tutta l'associazione, visibile anche agli utenti limitati a una sede. */
    public const SEDE_FACOLTATIVA = true;

    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasFactory;
    use HasUuids;

    public const TIPO_ENTRATA = 'entrata';

    public const TIPO_USCITA = 'uscita';

    public const TIPI = [
        self::TIPO_ENTRATA,
        self::TIPO_USCITA,
    ];

    protected $table = 'movimenti';

    protected $fillable = [
        'associazione_id',
        'sede_id',
        'tipo',
        'importo',
        'data',
        'categoria_id',
        'progetto_id',
        'descrizione',
        'metodo',
        'origine_type',
        'origine_id',
        'registrato_da',
    ];

    protected function casts(): array
    {
        return [
            'importo' => 'decimal:2',
            'data' => 'date',
        ];
    }

    /**
     * Importo con segno: positivo per le entrate, negativo per le uscite
     * (saldo progressivo della prima nota).
     */
    public function importoConSegno(): float
    {
        $importo = (float) $this->importo;

        return $this->tipo === self::TIPO_USCITA ? -$importo : $importo;
    }

    /**
     * @param  Builder<Movimento>  $query
     * @return Builder<Movimento>
     */
    public function scopeEntrate(Builder $query): Builder
    {
        return $query->where('tipo', self::TIPO_ENTRATA);
    }

    /**
     * @param  Builder<Movimento>  $query
     * @return Builder<Movimento>
     */
    public function scopeUscite(Builder $query): Builder
    {
        return $query->where('tipo', self::TIPO_USCITA);
    }

    /**
     * Movimenti del periodo [dal, al] (estremi inclusi).
     *
     * @param  Builder<Movimento>  $query
     * @return Builder<Movimento>
     */
    public function scopeDelPeriodo(Builder $query, \DateTimeInterface|string $dal, \DateTimeInterface|string $al): Builder
    {
        return $query->whereDate('data', '>=', $dal)->whereDate('data', '<=', $al);
    }

    /**
     * @return BelongsTo<CategoriaMovimento, $this>
     */
    public function categoria(): BelongsTo
    {
        return $this->belongsTo(CategoriaMovimento::class, 'categoria_id');
    }

    /**
     * Progetto a cui il movimento è imputato (f.13 — piano "Organizzazione 3").
     *
     * @return BelongsTo<Progetto, $this>
     */
    public function progetto(): BelongsTo
    {
        return $this->belongsTo(Progetto::class, 'progetto_id');
    }

    /**
     * Origine opzionale del movimento: Quota, Prenotazione o Donazione.
     *
     * @return MorphTo<Model, $this>
     */
    public function origine(): MorphTo
    {
        return $this->morphTo('origine');
    }

    /**
     * Admin che ha registrato il movimento manuale.
     *
     * @return BelongsTo<Utente, $this>
     */
    public function registratoDa(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'registrato_da');
    }
}
