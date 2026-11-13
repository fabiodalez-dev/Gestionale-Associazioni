<?php

namespace App\Http\Middleware\AreaSoci;

use App\Models\Socio;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replica del comportamento legacy (area-soci/index.php): se il socio
 * autenticato è stato cancellato (soft delete), gli è stato revocato
 * l'accesso (password_hash azzerata) o non è (più) un socio con diritto
 * di accesso (stato In Attesa o cessato), la sessione viene distrutta e
 * l'utente è rimandato alla login (logout forzato).
 */
class EnsureSocioValido
{
    public function handle(Request $request, Closure $next): Response
    {
        $socio = $request->user('socio');

        // Cancellato, accesso revocato (password azzerata) oppure non più / non
        // ancora socio (stato in attesa o cessato): la sessione non vale più
        if ($socio instanceof Socio && ! $socio->puoAccedereAreaSoci()) {
            Auth::guard('socio')->logout();
            // regenerate (non invalidate): le altre guard nello stesso browser sopravvivono
            $request->session()->regenerate();
            $request->session()->regenerateToken();

            return redirect()->route('area-soci.login');
        }

        return $next($request);
    }
}
