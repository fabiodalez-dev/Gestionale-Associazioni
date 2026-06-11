<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Utente;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Pagina profilo: cambio password e gestione 2FA (TOTP).
     */
    public function show(Request $request): View
    {
        /** @var Utente $utente */
        $utente = $request->user();

        $twoFactorPending = $utente->two_factor_secret !== null && $utente->two_factor_confirmed_at === null;
        $twoFactorEnabled = $utente->two_factor_secret !== null && $utente->two_factor_confirmed_at !== null;

        return view('profile.show', [
            'utente' => $utente,
            'twoFactorPending' => $twoFactorPending,
            'twoFactorEnabled' => $twoFactorEnabled,
            'twoFactorQrCodeSvg' => $twoFactorPending ? $utente->twoFactorQrCodeSvg() : null,
            'twoFactorSetupKey' => $twoFactorPending ? decrypt($utente->two_factor_secret) : null,
            'recoveryCodes' => $twoFactorEnabled ? $utente->recoveryCodes() : [],
        ]);
    }
}
