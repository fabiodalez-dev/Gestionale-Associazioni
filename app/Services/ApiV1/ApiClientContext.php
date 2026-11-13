<?php

namespace App\Services\ApiV1;

/**
 * Contesto del client autenticato sulla REST API v1.
 *
 * Astrazione comune sopra le due modalità di autenticazione (miglioramento 23):
 * - chiave legacy `api_keys` (Bearer 64-hex oppure header X-API-Key);
 * - personal access token Sanctum con abilities (le abilities usano la stessa
 *   sintassi dei permessi legacy: `soci:read`, `soci:*`, `*`).
 *
 * Una chiave/token è "globale" solo se dichiarata tale in modo esplicito
 * (api_keys.globale, oppure token di un super_admin senza tenant) e non è
 * vincolata ad alcuna associazione. Un associazione_id NULL da solo non basta:
 * una chiave rimasta senza associazione (per esempio dopo l'eliminazione del
 * tenant) non deve mai diventare cross-tenant. Il costruttore rifiuta un
 * contesto non globale senza associazione, così nessun controller può
 * interpretare il tenant NULL come "tutte le associazioni".
 *
 * Il rate limiting non passa da qui: il limiter api-v1 gira prima
 * dell'autenticazione, per IP e per hash della credenziale presentata.
 */
class ApiClientContext
{
    /**
     * @param  list<string>  $permessi
     */
    public function __construct(
        public readonly ?string $associazioneId,
        public readonly array $permessi,
        public readonly bool $globale = false,
    ) {
        if ($associazioneId === null && ! $globale) {
            throw new \InvalidArgumentException('Contesto API senza associazione e non globale.');
        }
    }

    public function isGlobale(): bool
    {
        return $this->globale && $this->associazioneId === null;
    }

    /**
     * Verifica un permesso con supporto wildcard: '*', match esatto
     * oppure wildcard di categoria ('soci:*' copre 'soci:read' e 'soci:write').
     */
    public function hasPermesso(string $permesso): bool
    {
        return self::permessoConcesso($this->permessi, $permesso);
    }

    /**
     * Regola unica dei permessi API (usata anche da ApiKey::hasPermesso).
     *
     * @param  array<int, mixed>  $permessi
     */
    public static function permessoConcesso(array $permessi, string $permesso): bool
    {
        if (in_array('*', $permessi, true) || in_array($permesso, $permessi, true)) {
            return true;
        }

        $categoria = explode(':', $permesso)[0];

        return in_array($categoria.':*', $permessi, true);
    }

    /**
     * Filtro tenant effettivo per liste/ricerche (parità con apiAssociationFilter legacy):
     * - client scopato → SEMPRE la sua associazione (il parametro è ignorato);
     * - client globale + ?associazione_id valido (36 char [a-f0-9-]) → quel tenant;
     * - client globale senza filtro (o filtro invalido, ignorato in silenzio) → null (tutti).
     */
    public function filtroAssociazione(?string $richiesto): ?string
    {
        if (! $this->isGlobale()) {
            return $this->associazioneId;
        }

        if (is_string($richiesto) && preg_match('/^[a-f0-9-]{36}$/i', $richiesto)) {
            return $richiesto;
        }

        return null;
    }
}
