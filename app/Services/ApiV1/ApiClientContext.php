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
 * Una chiave/token è "globale" quando non è vincolata ad alcuna associazione
 * (api_keys.associazione_id NULL, oppure token di un super_admin senza tenant).
 */
class ApiClientContext
{
    public const TIPO_API_KEY = 'api_key';

    public const TIPO_SANCTUM = 'sanctum';

    /**
     * @param  list<string>  $permessi
     */
    public function __construct(
        public readonly string $tipo,
        public readonly string $identificatore,
        public readonly ?string $associazioneId,
        public readonly array $permessi,
    ) {}

    public function isGlobale(): bool
    {
        return $this->associazioneId === null;
    }

    /**
     * Verifica un permesso con supporto wildcard: '*', match esatto
     * oppure wildcard di categoria ('soci:*' copre 'soci:read' e 'soci:write').
     */
    public function hasPermesso(string $permesso): bool
    {
        if (in_array('*', $this->permessi, true) || in_array($permesso, $this->permessi, true)) {
            return true;
        }

        $categoria = explode(':', $permesso)[0];

        return in_array($categoria.':*', $this->permessi, true);
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

    /**
     * Chiave per il rate limiting per-credenziale.
     */
    public function rateLimitKey(): string
    {
        return 'api-v1|'.$this->tipo.'|'.$this->identificatore;
    }
}
