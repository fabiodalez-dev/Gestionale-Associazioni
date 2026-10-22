<?php

namespace App\Support;

use App\Models\Utente;
use Illuminate\Database\Eloquent\Model;

/**
 * Perimetro dei dati per sede (Fase 8): permesso e perimetro sono separati.
 * I ruoli dicono cosa un utente può fare; le sedi assegnate dicono su quali
 * dati. Un utente dello staff (non admin) con almeno una sede assegnata vede
 * e modifica solo soci, tessere, quote, documenti, eventi, movimenti e spazi
 * di quelle sedi; gli eventi, movimenti e spazi "di tutta l'associazione"
 * (sede vuota) li vede ma non li modifica.
 *
 * Nessun limite per: super_admin, admin dell'associazione, staff senza sedi
 * assegnate, area soci, account pubblici, console e code.
 */
final class PerimetroSedi
{
    /**
     * Sedi dell'utente autenticato, null = nessun limite.
     *
     * @return list<string>|null
     */
    public static function sedi(): ?array
    {
        $utente = auth()->guard('web')->user();

        return $utente instanceof Utente ? self::sediDi($utente) : null;
    }

    /**
     * @return list<string>|null
     */
    public static function sediDi(Utente $utente): ?array
    {
        if ($utente->isSuperAdmin() || $utente->isAdminAssociazione()) {
            return null;
        }

        $sedi = $utente->sediAssegnateIds();

        return $sedi === [] ? null : $sedi;
    }

    public static function limitato(): bool
    {
        return self::sedi() !== null;
    }

    /**
     * Un utente limitato può modificare solo i record di una sua sede:
     * non quelli di tutta l'associazione (sede vuota).
     */
    public static function puoModificare(Utente $utente, Model $modello): bool
    {
        $sedi = self::sediDi($utente);

        return $sedi === null || in_array((string) $modello->getAttribute('sede_id'), $sedi, true);
    }
}
