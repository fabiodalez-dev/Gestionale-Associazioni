<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un'importazione da file (#55), con il report degli errori riga per riga.
 *
 * @property string $id
 * @property string $associazione_id
 * @property string|null $utente_id
 * @property string $tipo
 * @property string $nome_file
 * @property int $righe_lette
 * @property int $create
 * @property int $aggiornate
 * @property int $scartate
 * @property list<array{riga: int, errori: list<string>}>|null $errori
 * @property int $durata_ms
 * @property Carbon $created_at
 * @property-read Utente|null $utente
 */
class Importazione extends Model
{
    use BelongsToAssociazione;
    use HasUuids;
    use MassPrunable;

    public const TIPO_SOCI = 'soci';

    protected $table = 'importazioni';

    protected $fillable = [
        'associazione_id', 'utente_id', 'tipo', 'nome_file', 'righe_lette',
        'create', 'aggiornate', 'scartate', 'errori', 'durata_ms',
    ];

    protected function casts(): array
    {
        return [
            'errori' => 'array',
            'righe_lette' => 'integer',
            'create' => 'integer',
            'aggiornate' => 'integer',
            'scartate' => 'integer',
            'durata_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<Utente, $this> */
    public function utente(): BelongsTo
    {
        return $this->belongsTo(Utente::class);
    }

    /**
     * Lo storico resta 12 mesi (#55), poi model:prune lo elimina.
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->withoutGlobalScopes()->where('created_at', '<', now()->subYear());
    }
}
