<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Chiusura dell'associazione o di una sede (#34).
 *
 * @property string $id
 * @property string $associazione_id
 * @property string|null $sede_id
 * @property string $titolo
 * @property Carbon $data_inizio
 * @property Carbon $data_fine
 * @property bool $ogni_anno
 * @property bool $attiva
 * @property-read Sede|null $sede
 */
class Chiusura extends Model
{
    use BelongsToAssociazione;
    use HasUuids;

    protected $table = 'chiusure';

    protected $fillable = [
        'associazione_id',
        'sede_id',
        'titolo',
        'data_inizio',
        'data_fine',
        'ogni_anno',
        'attiva',
    ];

    protected function casts(): array
    {
        return [
            'data_inizio' => 'date',
            'data_fine' => 'date',
            'ogni_anno' => 'boolean',
            'attiva' => 'boolean',
        ];
    }

    /** @return BelongsTo<Sede, $this> */
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class);
    }

    /** Il giorno (Y-m-d) cade in questa chiusura? Per quelle annuali conta solo mese e giorno. */
    public function comprende(string $giorno): bool
    {
        if (! $this->ogni_anno) {
            return $giorno >= $this->data_inizio->toDateString() && $giorno <= $this->data_fine->toDateString();
        }

        $md = substr($giorno, 5);
        $da = $this->data_inizio->format('m-d');
        $a = $this->data_fine->format('m-d');

        // Periodo a cavallo di capodanno (es. 24/12 → 06/01)
        return $da <= $a ? ($md >= $da && $md <= $a) : ($md >= $da || $md <= $a);
    }
}
