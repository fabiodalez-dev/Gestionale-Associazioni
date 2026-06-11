<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\HasDefaultActivityLog;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventoPartecipante extends Model
{
    use BelongsToAssociazione;
    use HasDefaultActivityLog;
    use HasUuids;

    public const STATO_CONFERMATO = 'Confermato';

    public const STATO_FORSE = 'Forse';

    public const STATO_NON_PARTECIPA = 'Non Partecipa';

    public const STATI = [
        self::STATO_CONFERMATO,
        self::STATO_FORSE,
        self::STATO_NON_PARTECIPA,
    ];

    protected $table = 'eventi_partecipanti';

    public const UPDATED_AT = null;

    protected $fillable = [
        'associazione_id',
        'evento_id',
        'socio_id',
        'stato_partecipazione',
        'data_conferma',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'data_conferma' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Evento, $this>
     */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(Evento::class, 'evento_id');
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
