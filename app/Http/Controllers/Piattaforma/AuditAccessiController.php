<?php

namespace App\Http\Controllers\Piattaforma;

use App\Http\Controllers\Controller;
use App\Http\Requests\Piattaforma\FiltriAuditAccessiRequest;
use App\Models\Accesso;
use Illuminate\View\View;

/**
 * Audit accessi (f.25 del piano "Gestione 2"): consultazione della tabella
 * `accessi` (popolata dal listener RegistraAccesso di Fondamenta) con filtri
 * per guard, esito, email e periodo.
 *
 * Multi-tenancy: il global scope BelongsToAssociazione limita gli admin al
 * proprio tenant; il super_admin in vista globale vede tutte le righe,
 * incluse quelle senza tenant (tentativi su email sconosciute).
 */
class AuditAccessiController extends Controller
{
    public function index(FiltriAuditAccessiRequest $request): View
    {
        $filtri = $request->validated();

        $accessi = Accesso::query()
            ->with('autenticabile')
            ->when($filtri['guard'] ?? null, fn ($q, $guard) => $q->where('guard', $guard))
            ->when($filtri['esito'] ?? null, fn ($q, $esito) => $q->where('esito', $esito))
            ->when($filtri['email'] ?? null, fn ($q, $email) => $q->where('email_tentata', 'like', '%'.$email.'%'))
            ->when($filtri['dal'] ?? null, fn ($q, $dal) => $q->where('created_at', '>=', $dal.' 00:00:00'))
            ->when($filtri['al'] ?? null, fn ($q, $al) => $q->where('created_at', '<=', $al.' 23:59:59'))
            ->orderByDesc('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('piattaforma.audit-accessi.index', [
            'accessi' => $accessi,
            'filtri' => $filtri,
            'guards' => FiltriAuditAccessiRequest::GUARDS,
            'esiti' => Accesso::ESITI,
        ]);
    }
}
