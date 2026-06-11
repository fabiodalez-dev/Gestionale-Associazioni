<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Services\ApiV1\ApiClientContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Helper condivisi dei controller REST API v1: formato risposte con parità
 * legacy ({success, error, code} / {success, total, page, limit, ...}).
 */
trait ApiV1Responses
{
    protected function apiClient(Request $request): ApiClientContext
    {
        /** @var ApiClientContext $client */
        $client = $request->attributes->get('api_client');

        return $client;
    }

    protected function apiError(int $status, string $code, string $message): JsonResponse
    {
        return response()->json([
            'success' => false,
            'error' => $message,
            'code' => $code,
        ], $status);
    }

    /**
     * Parametri di paginazione legacy: page >= 1, limit 1-100 (default 20).
     *
     * @return array{page: int, limit: int}
     */
    protected function paginazione(Request $request): array
    {
        return [
            'page' => max(1, (int) $request->query('page', '1')),
            'limit' => min(100, max(1, (int) $request->query('limit', '20'))),
        ];
    }

    protected function isUuidLike(string $value): bool
    {
        return (bool) preg_match('/^[a-f0-9-]{36}$/i', $value);
    }

    protected function isUuid(string $value): bool
    {
        return (bool) preg_match(
            '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i',
            $value
        );
    }
}
