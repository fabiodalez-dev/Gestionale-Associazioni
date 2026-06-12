<?php

namespace App\Http\Controllers\Piattaforma;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Utente;
use App\Services\Piattaforma\OnboardingChecklist;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Onboarding admin (f.23 del piano "Gestione 2"): dismiss persistente
 * della card checklist mostrata in dashboard. La checklist è calcolata
 * da OnboardingChecklist sullo stato reale del tenant.
 */
class OnboardingController extends Controller
{
    public function dismiss(Request $request, OnboardingChecklist $checklist): RedirectResponse
    {
        /** @var Utente $utente */
        $utente = $request->user();

        // Stessa visibilità della card: amministratori del tenant
        // (operatore senza struttura.view non vede né card né dismiss)
        abort_unless(
            $utente->isSuperAdmin() || $utente->can('struttura.view') || $utente->isAdminAssociazione(),
            403
        );

        // Stessa risoluzione tenant della card (sessione popolata dal
        // middleware 'tenant' della rotta, con fallback all'associazione
        // dell'utente per coerenza con la dashboard)
        $tenantId = session('associazione_id')
            ?: (! $utente->isSuperAdmin() ? $utente->associazione_id : null);

        $associazione = Associazione::query()->findOrFail($tenantId);

        $checklist->dismetti($associazione);

        return redirect()
            ->route('dashboard')
            ->with('success', 'Checklist di configurazione nascosta.');
    }
}
