<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use App\Models\Concerns\NelPerimetroSedeTramite;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Consenso espresso in un modulo pubblico (registro GDPR, solo inserimento).
 */
class Consenso extends Model
{
    use BelongsToAssociazione;
    use HasUuids;
    use NelPerimetroSedeTramite;

    public const CHIAVE_PRIVACY = 'privacy';

    protected $table = 'consensi';

    protected $fillable = ['associazione_id', 'socio_id', 'chiave', 'testo', 'accettato', 'origine', 'ip'];

    protected function casts(): array
    {
        return ['accettato' => 'boolean'];
    }

    /**
     * @return BelongsTo<Socio, $this>
     */
    public function socio(): BelongsTo
    {
        return $this->belongsTo(Socio::class, 'socio_id');
    }
}
