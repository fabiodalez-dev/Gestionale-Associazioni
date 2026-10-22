<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Models\Sede;
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
 * consulta la matrice ruolo × permesso. Le sedi assegnate limitano i dati
 * visibili (PerimetroSedi): il ruolo dice cosa, la sede su quali dati. I permessi dei ruoli sono definiti in
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
            ->with(['roles', 'sedi'])
            ->orderByRaw('ruolo = ? desc', [Utente::RUOLO_ADMIN_ASSOCIAZIONE])
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get();

        $ruoliMatrice = array_merge(['admin_associazione'], array_keys(RuoliAssociazione::RUOLI_STAFF));

        return view('struttura.ruoli.index', [
            'staff' => $staff,
            // L'admin non è limitato a sedi: elenco completo dell'associazione
            'sedi' => Sede::query()->withoutGlobalScopes()->where('associazione_id', session('associazione_id'))->orderBy('nome')->get(['id', 'nome']),
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
            'sedi' => ['nullable', 'array'],
            'sedi.*' => ['uuid', Rule::exists('sedi', 'id')->where('associazione_id', $utente->associazione_id)],
        ], [
            'ruoli.required' => 'Seleziona almeno un ruolo.',
            'ruoli.min' => 'Seleziona almeno un ruolo.',
        ]);

        $utente->syncRoles(array_values(array_unique($dati['ruoli'])));
        $utente->assegnaSedi(array_values($dati['sedi'] ?? []));
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $etichette = collect($dati['ruoli'])->map(fn (string $r) => RuoliAssociazione::label($r))->join(', ');
        $sedi = $utente->sedi()->withoutGlobalScopes()->orderBy('nome')->pluck('nome');
        $perimetro = $sedi->isEmpty() ? 'tutte le sedi' : 'sedi '.$sedi->join(', ');

        return redirect()
            ->route('ruoli.index')
            ->with('success', 'Ruoli di '.$utente->nome_completo.' aggiornati: '.$etichette.' ('.$perimetro.').');
    }
}
