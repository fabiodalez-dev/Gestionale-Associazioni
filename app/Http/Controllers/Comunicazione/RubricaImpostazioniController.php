<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\RubricaContatto;
use App\Models\RubricaScheda;
use App\Settings\PartecipazioneSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Rubrica interna, lato segreteria (issue #14): attivazione, reciprocità,
 * campi disponibili, numeri e segnalazioni di uso improprio. Configurazione
 * dell'associazione: solo admin (come le altre pagine di configurazione).
 * La segreteria non vede i contenuti dei messaggi, che non si conservano.
 */
class RubricaImpostazioniController extends Controller
{
    public function index(Request $request): View
    {
        $this->autorizza($request);

        return view('comunicazione.rubrica.index', [
            'impostazioni' => $this->associazione($request)->impostazioni(PartecipazioneSettings::class),
            'schedeAttive' => RubricaScheda::query()->where('attiva', true)->count(),
            'contattiMese' => RubricaContatto::query()->where('created_at', '>=', now()->subMonth())->count(),
            'segnalati' => RubricaContatto::query()
                ->whereNotNull('segnalato_at')
                ->with([
                    'mittente' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
                    'destinatario' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
                ])
                ->latest('segnalato_at')
                ->limit(50)
                ->get(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->autorizza($request);

        $dati = $request->validate([
            'rubrica_attiva' => ['boolean'],
            'rubrica_reciprocita' => ['boolean'],
            'rubrica_campi' => ['nullable', 'array'],
            'rubrica_campi.*' => [Rule::in(array_keys(RubricaScheda::CAMPI))],
        ]);

        $this->associazione($request)->impostazioni(PartecipazioneSettings::class)->aggiorna([
            'rubrica_attiva' => $request->boolean('rubrica_attiva'),
            'rubrica_reciprocita' => $request->boolean('rubrica_reciprocita'),
            'rubrica_campi' => $dati['rubrica_campi'] ?? [],
        ]);

        return redirect()->route('rubrica-soci.index')->with('success', 'Impostazioni della rubrica salvate.');
    }

    private function autorizza(Request $request): void
    {
        abort_unless($request->user()->isAdminAssociazione() || $request->user()->isSuperAdmin(), 403);
    }

    private function associazione(Request $request): Associazione
    {
        return Associazione::query()->findOrFail(session('associazione_id') ?? $request->user()->associazione_id);
    }
}
