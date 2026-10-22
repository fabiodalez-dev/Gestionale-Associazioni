<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Http\Requests\AreaSoci\ImpostaPasswordRequest;
use App\Models\Socio;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class SetPasswordController extends Controller
{
    /**
     * Form di impostazione password: il form è mostrato solo con token
     * valido e non scaduto (come il legacy set-password.php).
     */
    public function show(string $token): View
    {
        $socio = $this->socioByToken($token);

        return view('area-soci.set-password', [
            'token' => $token,
            'tokenValido' => $socio !== null,
            // Per l'indicatore di robustezza: la password non deve contenere i dati del socio
            'datiSocio' => $socio === null ? [] : [$socio->email, $socio->nome, $socio->cognome],
        ]);
    }

    public function store(ImpostaPasswordRequest $request, string $token): RedirectResponse
    {
        $socio = $this->socioByToken($token);

        if ($socio === null) {
            return redirect()
                ->route('area-soci.set-password', ['token' => $token])
                ->withErrors(['token' => 'Token non valido o scaduto. Richiedi un nuovo link.']);
        }

        // Il cast 'hashed' applica l'hash; token monouso azzerato
        $socio->forceFill([
            'password_hash' => $request->string('password')->toString(),
            'password_reset_token' => null,
            'password_reset_expires' => null,
        ])->save();

        return redirect()
            ->route('area-soci.login')
            ->with('status', 'Password impostata correttamente. Ora puoi accedere all\'area soci.');
    }

    private function socioByToken(string $token): ?Socio
    {
        if ($token === '') {
            return null;
        }

        /** @var Socio|null */
        return Socio::allTenants()
            ->where('password_reset_token', $token)
            ->where('password_reset_expires', '>', now())
            ->first();
    }
}
