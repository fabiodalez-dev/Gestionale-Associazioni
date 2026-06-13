<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Voce dello storico aggiornamenti. NON multi-tenant: l'update riguarda
 * l'intera installazione, gestito solo dal super_admin.
 */
class UpdateLog extends Model
{
    use HasUuids;

    public const STATO_AVVIATO = 'avviato';

    public const STATO_COMPLETATO = 'completato';

    public const STATO_FALLITO = 'fallito';

    public const STATO_ROLLBACK = 'rollback';

    public $timestamps = false;

    protected $fillable = [
        'from_version', 'to_version', 'stato',
        'backup_path', 'messaggio_errore', 'eseguito_da',
        'avviato_at', 'completato_at',
    ];

    protected function casts(): array
    {
        return [
            'avviato_at' => 'datetime',
            'completato_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Utente, $this> */
    public function esecutore(): BelongsTo
    {
        return $this->belongsTo(Utente::class, 'eseguito_da');
    }
}
