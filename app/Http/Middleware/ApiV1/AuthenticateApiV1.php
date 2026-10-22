<?php

namespace App\Http\Middleware\ApiV1;

use App\Models\ApiKey;
use App\Models\Utente;
use App\Services\ApiV1\ApiClientContext;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autenticazione REST API v1 con doppio meccanismo (miglioramento 23):
 *
 * 1. Chiave legacy `api_keys`: token esadecimale di 64 caratteri passato come
 *    `Authorization: Bearer {key}` oppure come header `X-API-Key: {key}`.
 *    Chiave scopata (associazione_id valorizzato) o globale (NULL, super_admin).
 * 2. Personal access token Sanctum (`Authorization: Bearer {id}|{token}`) con
 *    abilities nella stessa sintassi dei permessi legacy (`soci:read`, `*`, …).
 *
 * Codici errore con parità legacy: auth_missing, auth_invalid, key_disabled,
 * association_disabled, key_expired, permission_denied.
 *
 * Uso: ->middleware(AuthenticateApiV1::class.':soci:read')
 * Il contesto autenticato è in $request->attributes->get('api_client').
 */
class AuthenticateApiV1
{
    public function handle(Request $request, Closure $next, ?string $permesso = null): Response
    {
        $bearer = $request->bearerToken();
        $headerKey = $request->header('X-API-Key');

        if (($bearer === null || $bearer === '') && ($headerKey === null || $headerKey === '')) {
            return $this->error(
                401,
                'auth_missing',
                'Autenticazione richiesta. Invia la chiave con header "Authorization: Bearer {api_key}" oppure "X-API-Key: {api_key}".'
            );
        }

        // Chiave legacy: esattamente 64 caratteri esadecimali (256 bit)
        $legacyKey = null;

        if (is_string($bearer) && preg_match('/^[a-fA-F0-9]{64}$/', $bearer)) {
            $legacyKey = $bearer;
        } elseif (is_string($headerKey) && preg_match('/^[a-fA-F0-9]{64}$/', $headerKey)) {
            $legacyKey = $headerKey;
        }

        if ($legacyKey !== null) {
            return $this->autenticaApiKey($request, $next, $legacyKey, $permesso);
        }

        // Bearer non 64-hex: tentativo con token Sanctum
        if (is_string($bearer) && $bearer !== '') {
            return $this->autenticaSanctum($request, $next, $bearer, $permesso);
        }

        // X-API-Key con formato non valido = come credenziale invalida
        return $this->error(401, 'auth_invalid', 'API key non valida.');
    }

    private function autenticaApiKey(Request $request, Closure $next, string $token, ?string $permesso): Response
    {
        /** @var ApiKey|null $apiKey */
        $apiKey = ApiKey::query()->with('associazione:id,nome,attiva')->where('api_key', $token)->first();

        if (! $apiKey) {
            return $this->error(401, 'auth_invalid', 'API key non valida.');
        }

        if (! $apiKey->attiva) {
            return $this->error(403, 'key_disabled', 'API key disattivata.');
        }

        // Chiave scopata con associazione disattivata (le globali saltano il check)
        if (! $apiKey->isGlobale() && ! $apiKey->associazione?->attiva) {
            return $this->error(403, 'association_disabled', 'L\'associazione collegata a questa API key non è attiva.');
        }

        // La chiave è valida per tutto il giorno della data di scadenza
        if ($apiKey->scadenza !== null && $apiKey->scadenza->lt(now()->startOfDay())) {
            return $this->error(403, 'key_expired', 'API key scaduta.');
        }

        $context = new ApiClientContext(
            tipo: ApiClientContext::TIPO_API_KEY,
            identificatore: $apiKey->id,
            associazioneId: $apiKey->associazione_id,
            associazioneNome: $apiKey->associazione?->nome,
            permessi: $apiKey->permessi ?? [],
        );

        if ($permesso !== null && ! $context->hasPermesso($permesso)) {
            return $this->error(403, 'permission_denied', "Permesso '{$permesso}' non concesso a questa API key.");
        }

        // Touch ultimo_utilizzo fire-and-forget, senza eventi/audit log
        try {
            ApiKey::query()->whereKey($apiKey->id)->update(['ultimo_utilizzo' => now()]);
        } catch (\Throwable $e) {
            report($e);
        }

        $request->attributes->set('api_client', $context);
        $request->attributes->set('api_key', $apiKey);

        return $next($request);
    }

    private function autenticaSanctum(Request $request, Closure $next, string $bearer, ?string $permesso): Response
    {
        $accessToken = PersonalAccessToken::findToken($bearer);

        if (! $accessToken) {
            return $this->error(401, 'auth_invalid', 'Token non valido.');
        }

        if ($accessToken->expires_at !== null && $accessToken->expires_at->isPast()) {
            return $this->error(403, 'key_expired', 'Token scaduto.');
        }

        $utente = $accessToken->tokenable;

        if (! $utente instanceof Utente || ! $utente->attivo) {
            return $this->error(403, 'key_disabled', 'Token disattivato.');
        }

        // Tenant del token: l'associazione dell'utente; i super_admin senza
        // associazione hanno token globali cross-tenant.
        if (! $utente->isSuperAdmin() && empty($utente->associazione_id)) {
            return $this->error(403, 'key_disabled', 'Token non utilizzabile: utente senza associazione.');
        }

        $associazione = $utente->associazione_id ? $utente->associazione : null;

        if ($utente->associazione_id && ! $associazione?->attiva) {
            return $this->error(403, 'association_disabled', 'L\'associazione collegata a questo token non è attiva.');
        }

        $context = new ApiClientContext(
            tipo: ApiClientContext::TIPO_SANCTUM,
            identificatore: (string) $accessToken->id,
            associazioneId: $utente->associazione_id ?: null,
            associazioneNome: $associazione?->nome,
            permessi: $accessToken->abilities ?? [],
        );

        if ($permesso !== null && ! $context->hasPermesso($permesso)) {
            return $this->error(403, 'permission_denied', "Permesso '{$permesso}' non concesso a questo token.");
        }

        try {
            $accessToken->forceFill(['last_used_at' => now()])->saveQuietly();
        } catch (\Throwable $e) {
            report($e);
        }

        $request->attributes->set('api_client', $context);
        // Perimetro per sede del proprietario del token (PerimetroSedi)
        $request->attributes->set('api_utente', $utente);

        return $next($request);
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ], $status);
    }
}
