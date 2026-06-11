<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Http\Requests\AreaSoci\LoginSocioRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Pagina di login del portale soci (due tab: Accedi / Reimposta password).
     */
    public function show(): View|RedirectResponse
    {
        if (Auth::guard('socio')->check()) {
            return redirect()->route('area-soci.dashboard');
        }

        return view('area-soci.login');
    }

    public function login(LoginSocioRequest $request): RedirectResponse
    {
        $request->authenticate();

        // Anti session-fixation (come session_regenerate_id(true) nel legacy)
        $request->session()->regenerate();

        return redirect()->intended(route('area-soci.dashboard'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::guard('socio')->logout();

        // Nuovo session id + nuovo token CSRF. NON si usa invalidate():
        // le guard sono separate e un'eventuale sessione admin ('web')
        // nello stesso browser deve sopravvivere (fix del bug legacy).
        $request->session()->regenerate();
        $request->session()->regenerateToken();

        return redirect()->route('area-soci.login');
    }
}
