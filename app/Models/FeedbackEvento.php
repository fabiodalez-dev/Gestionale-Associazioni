<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Feedback post-evento (f.11 del piano): un feedback per prenotazione
 * (unique a DB), raccolto via link pubblico firmato dal token.
 *
 * Ciclo di vita: creato all'invio dell'email (inviato_at), compilato dal
 * partecipante (voto 1–5 + commento, compilato_at).
 */
class FeedbackEvento extends Model
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;

    public const VOTO_MIN = 1;

    public const VOTO_MAX = 5;

    protected $table = 'feedback_eventi';

    protected $fillable = [
        'associazione_id',
        'evento_id',
        'prenotazione_id',
        'voto',
        'commento',
        'token',
        'inviato_at',
        'compilato_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected function casts(): array
    {
        return [
            'voto' => 'integer',
            'inviato_at' => 'datetime',
            'compilato_at' => 'datetime',
        ];
    }

    /**
     * Token del link pubblico (64 char esadecimali, colonna char(64) unique).
     */
    public static function generaToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function isCompilato(): bool
    {
        return $this->compilato_at !== null;
    }

    /**
     * @param  Builder<FeedbackEvento>  $query
     * @return Builder<FeedbackEvento>
     */
    public function scopeCompilati(Builder $query): Builder
    {
        return $query->whereNotNull('compilato_at');
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    /**
     * @return BelongsTo<Prenotazione, $this>
     */
    public function prenotazione(): BelongsTo
    {
        return $this->belongsTo(Prenotazione::class, 'prenotazione_id');
    }
}
