<?php

namespace App\Http\Controllers\FrontendPubblico;

use App\Http\Controllers\Controller;
use App\Http\Requests\FrontendPubblico\LoginAccountRequest;
use App\Models\Account;
use App\Models\Associazione;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Login/logout degli account sul frontend pubblico (guard 'account').
 * Lookup tenant-scoped nel Form Request; pattern area-soci per la
 * gestione sessione (regenerate, mai invalidate: le altre guard
 * nello stesso browser sopravvivono).
 */
class LoginController extends Controller
{
    public function show(Associazione $associazione): View|RedirectResponse
    {
        if (Auth::guard('account')->check()) {
            return redirect()->route('pubblico.home.show', $associazione->slug);
        }

        return view('pubblico.auth.login', ['associazione' => $associazione]);
    }

    public function login(LoginAccountRequest $request, Associazione $associazione): RedirectResponse
    {
        $request->authenticate();

        // Anti session-fixation
        $request->session()->regenerate();

        $account = Auth::guard('account')->user();

        if ($account instanceof Account) {
            $account->forceFill(['ultimo_accesso_at' => now()])->save();
        }

        return redirect()->intended(route('pubblico.home.show', $associazione->slug));
    }

    public function logout(Request $request, Associazione $associazione): RedirectResponse
    {
        Auth::guard('account')->logout();

        // Nuovo session id + token CSRF, senza invalidate(): un'eventuale
        // sessione admin/socio nello stesso browser deve sopravvivere
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('pubblico.home.show', $associazione->slug);
    }
}
