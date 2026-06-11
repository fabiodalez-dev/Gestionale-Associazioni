<?php

namespace App\Http\Middleware\FrontendPubblico;

use App\Models\Associazione;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Variante di 'auth' per il frontend pubblico: i visitatori non autenticati
 * vengono rediretti alla login pubblica DEL TENANT corrente
 * (pubblico.login, /a/{slug}/login), non a quella admin né a quella soci.
 */
class AutenticaAccount extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        $slug = static::slugTenantCorrente($request);

        if ($slug !== null && Route::has('pubblico.login')) {
            return route('pubblico.login', ['associazione' => $slug]);
        }

        return null;
    }

    /**
     * Slug del tenant corrente dal parametro di rotta {associazione:slug}.
     */
    public static function slugTenantCorrente(Request $request): ?string
    {
        $associazione = $request->route('associazione');

        if ($associazione instanceof Associazione) {
            return $associazione->slug;
        }

        return is_string($associazione) ? $associazione : null;
    }
}
