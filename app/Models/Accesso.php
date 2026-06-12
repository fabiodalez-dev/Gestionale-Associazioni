<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAssociazione;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit degli accessi (f.25 del piano "Gestione 2"): una riga per ogni
 * Login/Failed/Logout su qualsiasi guard ('web', 'socio', 'account'),
 * scritta best-effort dal subscriber App\Listeners\RegistraAccesso.
 *
 * associazione_id è NULL quando il tenant non è determinabile (tentativo
 * fallito su email sconosciuta) o per i super_admin: quelle righe sono
 * visibili solo nella vista globale del super_admin.
 * Nessun activity log: è esso stesso un log ad alto volume.
 */
class Accesso extends Model
{
    use BelongsToAssociazione;
    use HasFactory;
    use HasUuids;

    public const ESITO_LOGIN = 'login';

    public const ESITO_FALLITO = 'fallito';

    public const ESITO_LOGOUT = 'logout';

    public const ESITI = [
        self::ESITO_LOGIN,
        self::ESITO_FALLITO,
        self::ESITO_LOGOUT,
    ];

    protected $table = 'accessi';

    protected $fillable = [
        'associazione_id',
        'guard',
        'autenticabile_type',
        'autenticabile_id',
        'email_tentata',
        'ip',
        'user_agent',
        'esito',
    ];

    /**
     * Tentativi falliti.
     *
     * @param  Builder<Accesso>  $query
     * @return Builder<Accesso>
     */
    public function scopeFalliti(Builder $query): Builder
    {
        return $query->where('esito', self::ESITO_FALLITO);
    }

    /**
     * Tentativi falliti recenti sulla stessa email, su tutti i tenant
     * (per l'alert f.25: 5 falliti in 10 minuti).
     *
     * @param  Builder<Accesso>  $query
     * @return Builder<Accesso>
     */
    public function scopeFallitiRecentiPerEmail(Builder $query, string $email, int $minuti = 10): Builder
    {
        return $query
            ->withoutGlobalScope('associazione')
            ->falliti()
            ->where('email_tentata', $email)
            ->where('created_at', '>=', now()->subMinutes($minuti));
    }

    /**
     * Utente/Socio/Account autenticato (NULL su tentativo senza match).
     *
     * @return MorphTo<Model, $this>
     */
    public function autenticabile(): MorphTo
    {
        return $this->morphTo('autenticabile');
    }
}
