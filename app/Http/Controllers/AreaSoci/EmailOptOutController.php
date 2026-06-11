<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\Socio;
use Illuminate\View\View;

/**
 * Opt-out dalle comunicazioni email tramite token (link nel footer delle
 * email). Rotta pubblica: il GET mostra una pagina di conferma, il POST
 * (con CSRF) applica l'opt-out — nessun cambio di stato via GET.
 */
class EmailOptOutController extends Controller
{
    public function show(string $token): View
    {
        $socio = $this->socioByToken($token);

        return view('area-soci.optout', [
            'socio' => $socio,
            'token' => $token,
            'confermato' => (bool) $socio->email_opt_out,
        ]);
    }

    public function store(string $token): View
    {
        $socio = $this->socioByToken($token);

        $socio->forceFill(['email_opt_out' => true])->save();

        return view('area-soci.optout', [
            'socio' => $socio,
            'token' => $token,
            'confermato' => true,
        ]);
    }

    private function socioByToken(string $token): Socio
    {
        /** @var Socio */
        return Socio::allTenants()
            ->where('email_opt_out_token', $token)
            ->firstOrFail();
    }
}
