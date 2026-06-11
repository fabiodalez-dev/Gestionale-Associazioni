<?php

namespace App\Models\Concerns;

use App\Models\Associazione;
use App\Models\Utente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Multi-tenancy: global scope su associazione_id.
 *
 * - utenti non super_admin: scope sempre sulla propria associazione_id;
 * - super_admin: scope sull'associazione selezionata in sessione
 *   (session('associazione_id')); se nessuna è selezionata, nessuno scope (vista globale);
 * - contesto console/seeding senza utente né sessione: nessuno scope.
 *
 * Alla creazione, associazione_id viene auto-valorizzata dal contesto corrente
 * se non impostata esplicitamente.
 */
trait BelongsToAssociazione
{
    public static function bootBelongsToAssociazione(): void
    {
        static::addGlobalScope('associazione', function (Builder $builder) {
            $associazioneId = static::currentAssociazioneId();

            if ($associazioneId !== null) {
                $builder->where(
                    $builder->qualifyColumn('associazione_id'),
                    $associazioneId
                );
            }
        });

        static::creating(function (self $model) {
            if (empty($model->associazione_id)) {
                $model->associazione_id = static::currentAssociazioneId();
            }
        });
    }

    /**
     * Risolve l'associazione corrente dal contesto (utente autenticato o sessione).
     */
    public static function currentAssociazioneId(): ?string
    {
        $user = auth()->user();

        if ($user instanceof Utente) {
            if ($user->isSuperAdmin()) {
                // super_admin: tenant selezionato in sessione, oppure vista globale (nessuno scope)
                return static::sessionAssociazioneId();
            }

            return $user->associazione_id ?: static::sessionAssociazioneId();
        }

        // Guard 'socio' (area soci): scope sull'associazione del socio
        if ($user !== null && isset($user->associazione_id) && $user->associazione_id) {
            return $user->associazione_id;
        }

        return static::sessionAssociazioneId();
    }

    protected static function sessionAssociazioneId(): ?string
    {
        return app()->bound('session') ? session('associazione_id') : null;
    }

    /**
     * @return BelongsTo<Associazione, $this>
     */
    public function associazione(): BelongsTo
    {
        return $this->belongsTo(Associazione::class, 'associazione_id');
    }

    /**
     * Query esplicita su un tenant, ignorando lo scope globale.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForAssociazione(Builder $query, string $associazioneId): Builder
    {
        return $query->withoutGlobalScope('associazione')
            ->where($query->qualifyColumn('associazione_id'), $associazioneId);
    }

    /**
     * Query senza alcun filtro tenant (solo super_admin / contesti di sistema).
     *
     * @return Builder<static>
     */
    public static function allTenants(): Builder
    {
        return static::query()->withoutGlobalScope('associazione');
    }
}
