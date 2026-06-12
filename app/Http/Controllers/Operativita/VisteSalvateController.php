<?php

namespace App\Http\Controllers\Operativita;

use App\Http\Controllers\Controller;
use App\Models\SavedFilter;
use App\Models\Utente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Viste salvate (f.5 del piano "Gestione 2"): estende il pattern SavedFilter
 * (scope esistente 'soci', gestito dal modulo soci) alle liste tessere,
 * quote e prenotazioni evento. L'applicazione di una vista è una semplice
 * navigazione con i parametri salvati in query string (nessuna modifica ai
 * controller delle pagine di destinazione); il salvataggio fa upsert su
 * (associazione, utente, scope, nome) come per i preset soci, con opzione
 * "condivisa" per tutto il tenant (user_id NULL).
 */
class VisteSalvateController extends Controller
{
    /**
     * Configurazione per scope: permesso richiesto e whitelist dei
     * parametri filtro salvabili (gli stessi letti dalle pagine target).
     *
     * @var array<string, array{permesso: string, params: list<string>}>
     */
    public const SCOPES = [
        'tessere' => [
            'permesso' => 'tessere.view',
            'params' => ['anno', 'search', 'stato'],
        ],
        'quote' => [
            'permesso' => 'quote.view',
            'params' => ['year', 'status'],
        ],
        'prenotazioni' => [
            'permesso' => 'eventi.view',
            'params' => ['stato'],
        ],
    ];

    public function store(Request $request, string $scope): RedirectResponse
    {
        $config = self::SCOPES[$scope] ?? abort(404);

        $this->autorizza($request->user(), $config['permesso']);

        $validated = $request->validate([
            'preset_name' => ['required', 'string', 'max:255'],
            'condiviso' => ['nullable', 'boolean'],
        ]);

        $params = [];
        foreach ($config['params'] as $chiave) {
            if ($request->filled($chiave)) {
                $params[$chiave] = $request->input($chiave);
            }
        }

        $vista = SavedFilter::updateOrCreate(
            [
                'associazione_id' => SavedFilter::currentAssociazioneId(),
                'user_id' => $request->boolean('condiviso') ? null : $request->user()->id,
                'scope' => $scope,
                'name' => $validated['preset_name'],
            ],
            ['params_json' => $params]
        );

        return back()->with('success', "Vista \"{$vista->name}\" salvata.");
    }

    public function destroy(Request $request, SavedFilter $filtro): RedirectResponse
    {
        // Gestiamo solo gli scope di questo modulo (i preset 'soci' hanno
        // le proprie rotte nel modulo soci)
        $config = self::SCOPES[$filtro->scope] ?? abort(404);

        $this->autorizza($request->user(), $config['permesso']);

        // Eliminabili le viste proprie o quelle condivise del tenant
        // (stessa regola della SavedFilterPolicy per i preset soci)
        abort_unless(
            $filtro->user_id === null || $filtro->user_id === $request->user()->id,
            403
        );

        $filtro->delete();

        return back()->with('success', 'Vista eliminata.');
    }

    /**
     * Permesso granulare con fallback su ruolo, come AuthorizesModulePermissions
     * (in ambienti senza RolesSeeder i permessi spatie non esistono).
     */
    private function autorizza(Utente $utente, string $permesso): void
    {
        if ($utente->isSuperAdmin()) {
            return;
        }

        try {
            abort_unless($utente->hasPermissionTo($permesso), 403);
        } catch (\Throwable $e) {
            if ($e instanceof HttpException) {
                throw $e;
            }

            // Permesso non definito: fallback sui ruoli applicativi
            abort_unless(
                in_array($utente->ruolo, [Utente::RUOLO_ADMIN_ASSOCIAZIONE, Utente::RUOLO_OPERATORE], true),
                403
            );
        }
    }
}
