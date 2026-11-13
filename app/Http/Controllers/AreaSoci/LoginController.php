<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Http\Requests\AreaSoci\LoginSocioRequest;
use App\Models\Socio;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class LoginController extends Controller
{
    /**
     * Pagina di login del portale soci (due tab: Accedi / Reimposta password).
     * Con una scelta dell'associazione in sospeso (stessa email e stessa
     * password in più associazioni) mostra invece l'elenco da cui scegliere.
     */
    public function show(Request $request): View|RedirectResponse
    {
        if (Auth::guard('socio')->check()) {
            return redirect()->route('area-soci.dashboard');
        }

        $ids = LoginSocioRequest::idsInSospeso($request->session()->get(LoginSocioRequest::SESSIONE_SCELTA));
        if ($ids !== []) {
            $soci = Socio::allTenants()
                ->with('associazione:id,nome')
                ->whereKey($ids)
                ->get()
                ->sortBy(fn (Socio $socio) => mb_strtolower((string) $socio->associazione?->nome))
                ->values();

            if ($soci->isNotEmpty()) {
                return view('area-soci.scelta-associazione', ['soci' => $soci]);
            }
        }
        $request->session()->forget(LoginSocioRequest::SESSIONE_SCELTA);

        return view('area-soci.login');
    }

    public function login(LoginSocioRequest $request): RedirectResponse
    {
        if ($request->annullaScelta()) {
            $request->session()->forget(LoginSocioRequest::SESSIONE_SCELTA);

            return redirect()->route('area-soci.login');
        }

        if ($request->isScelta()) {
            $request->completaScelta();
        } elseif (! $request->authenticate()) {
            // Password corretta per più associazioni: si sceglie dalla pagina di login
            $request->session()->regenerate();

            return redirect()->route('area-soci.login');
        }

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
