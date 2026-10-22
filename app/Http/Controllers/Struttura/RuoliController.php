<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Utente;
use App\Support\RuoliAssociazione;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\Permission\PermissionRegistrar;

/**
 * "Ruoli e permessi" (/ruoli): l'admin dell'associazione assegna le cariche
 * (presidente, tesoriere, segretario…) agli operatori del proprio staff e
 * consulta la matrice ruolo × permesso. I permessi dei ruoli sono definiti in
 * App\Support\RuoliAssociazione e allineati da RolesSeeder.
 */
class RuoliController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('gestisciRuoli', Utente::class);

        $staff = Utente::query()
            ->where('associazione_id', session('associazione_id'))
            ->where('ruolo', '!=', Utente::RUOLO_SUPER_ADMIN)
            ->with('roles')
            ->orderByRaw('ruolo = ? desc', [Utente::RUOLO_ADMIN_ASSOCIAZIONE])
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get();

        $ruoliMatrice = array_merge(['admin_associazione'], array_keys(RuoliAssociazione::RUOLI_STAFF));

        return view('struttura.ruoli.index', [
            'staff' => $staff,
            'ruoliStaff' => RuoliAssociazione::RUOLI_STAFF,
            'moduli' => RuoliAssociazione::MODULI,
            'azioni' => RuoliAssociazione::AZIONI,
            'ruoliMatrice' => $ruoliMatrice,
            'permessiPerRuolo' => collect($ruoliMatrice)->mapWithKeys(fn (string $r) => [$r => RuoliAssociazione::permessi($r)])->all(),
        ]);
    }

    public function update(Request $request, Utente $utente): RedirectResponse
    {
        Gate::authorize('assegnaRuoli', $utente);

        $dati = $request->validate([
            'ruoli' => ['required', 'array', 'min:1'],
            'ruoli.*' => ['string', Rule::in(array_keys(RuoliAssociazione::RUOLI_STAFF))],
        ], [
            'ruoli.required' => 'Seleziona almeno un ruolo.',
            'ruoli.min' => 'Seleziona almeno un ruolo.',
        ]);

        $utente->syncRoles(array_values(array_unique($dati['ruoli'])));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $etichette = collect($dati['ruoli'])->map(fn (string $r) => RuoliAssociazione::label($r))->join(', ');

        return redirect()
            ->route('ruoli.index')
            ->with('success', 'Ruoli di '.$utente->nome_completo.' aggiornati: '.$etichette.'.');
    }
}
