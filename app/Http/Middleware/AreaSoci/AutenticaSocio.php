<?php

namespace App\Http\Middleware\AreaSoci;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;

/**
 * Variante di 'auth' per il portale soci: i visitatori non autenticati
 * vengono rediretti alla login dell'area soci (non a quella admin).
 */
class AutenticaSocio extends Authenticate
{
    protected function redirectTo(Request $request): ?string
    {
        return $request->expectsJson() ? null : route('area-soci.login');
    }
}
