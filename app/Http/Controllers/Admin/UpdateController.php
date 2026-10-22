<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PerformUpdateRequest;
use App\Models\UpdateLog;
use App\Models\Utente;
use App\Services\Sistema\EnvEditor;
use App\Services\Update\UpdateService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Pagina "Aggiornamenti" (/aggiornamenti) — SOLO super_admin.
 * Versione corrente vs ultima release GitHub, requisiti, aggiornamento
 * automatico o da pacchetto caricato, stato in tempo reale, backup, storico,
 * sblocco della manutenzione, token GitHub.
 */
class UpdateController extends Controller
{
    public function __construct(private readonly UpdateService $updater) {}

    public function index(Request $request): View
    {
        $this->soloSuperAdmin($request);

        return view('aggiornamenti.index', [
            'stato' => $this->updater->controllaAggiornamenti(),
            'selfUpdate' => $this->updater->selfUpdateAbilitato(),
            'requisiti' => $this->updater->requisiti(),
            'inCorso' => $this->updater->inCorso(),
            'manutenzioneScaduta' => $this->updater->manutenzioneScaduta(),
            'backup' => array_slice($this->updater->elencoBackup(), 0, 10),
            'tokenPresente' => filled(config('update.github.token')),
            'canale' => (string) config('update.channel', 'stable'),
            'storico' => UpdateLog::query()
                ->with('esecutore')
                ->orderByDesc('avviato_at')
                ->limit(15)
                ->get(),
        ]);
    }

    /** Controllo live (AJAX) della disponibilità di un aggiornamento. */
    public function controlla(Request $request): JsonResponse
    {
        $this->soloSuperAdmin($request);

        return response()->json($this->updater->controllaAggiornamenti());
    }

    /** Stato per il polling durante l'aggiornamento. */
    public function stato(Request $request): JsonResponse
    {
        $this->soloSuperAdmin($request);

        return response()->json($this->updater->stato())->header('Cache-Control', 'no-store');
    }

    /** Aggiornamento dalla release GitHub. */
    public function aggiorna(PerformUpdateRequest $request): JsonResponse|RedirectResponse
    {
        $this->soloSuperAdmin($request);
        if (! $this->updater->selfUpdateAbilitato()) {
            return $this->rispondi($request, false, 'Aggiornamento dalla pagina web disattivato (UPDATE_SELF_ENABLED): usa php artisan app:aggiorna.');
        }

        /** @var Utente $utente */
        $utente = $request->user();
        $esito = $this->updater->applicaAggiornamento($request->validated('versione'), $utente);

        return $this->rispondi(
            $request,
            $esito['success'],
            $esito['success'] ? 'Aggiornamento alla versione '.$esito['versione'].' completato.' : ($esito['error'] ?? 'Aggiornamento non riuscito.'),
            $esito['log_id'],
        );
    }

    /** Aggiornamento da un pacchetto zip caricato (hosting senza accesso a GitHub). */
    public function carica(Request $request): JsonResponse|RedirectResponse
    {
        $this->soloSuperAdmin($request);
        if (! $this->updater->selfUpdateAbilitato()) {
            return $this->rispondi($request, false, 'Aggiornamento dalla pagina web disattivato (UPDATE_SELF_ENABLED): usa php artisan app:aggiorna.');
        }

        // Validazione a mano: le eccezioni di validazione sulle rotte web
        // redirigono sempre (bootstrap/app.php rende JSON solo per api/*),
        // mentre la pagina chiama questo endpoint via fetch
        $validatore = Validator::make($request->all(), [
            'pacchetto' => ['required', 'file', 'mimes:zip', 'max:204800'],
        ], [], ['pacchetto' => 'pacchetto']);
        if ($validatore->fails()) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $validatore->errors()->first(), 'errors' => $validatore->errors()->toArray()], 422);
            }

            return redirect()->route('aggiornamenti.index')->withErrors($validatore);
        }

        $file = $request->file('pacchetto');
        $cartella = storage_path('app/updates');
        $nome = 'caricato_'.now()->format('YmdHis').'.zip';
        $file->move($cartella, $nome);
        $percorso = $cartella.'/'.$nome;

        /** @var Utente $utente */
        $utente = $request->user();
        try {
            $esito = $this->updater->applicaAggiornamento('0.0.0', $utente, $percorso);
        } finally {
            @unlink($percorso);
        }

        return $this->rispondi(
            $request,
            $esito['success'],
            $esito['success'] ? 'Aggiornamento alla versione '.$esito['versione'].' completato dal pacchetto caricato.' : ($esito['error'] ?? 'Aggiornamento non riuscito.'),
            $esito['log_id'],
        );
    }

    /** Toglie una manutenzione rimasta accesa da un aggiornamento interrotto. */
    public function sbloccaManutenzione(Request $request): RedirectResponse
    {
        $this->soloSuperAdmin($request);

        return $this->updater->sbloccaManutenzione()
            ? redirect()->route('aggiornamenti.index')->with('success', 'Modalità manutenzione rimossa.')
            : redirect()->route('aggiornamenti.index')->withErrors(['update' => 'Nessuna manutenzione scaduta da sbloccare.']);
    }

    /** Download di un backup (database) creato dall'updater o da spatie/laravel-backup. */
    public function scaricaBackup(Request $request, string $nome): BinaryFileResponse
    {
        $this->soloSuperAdmin($request);

        foreach ($this->updater->elencoBackup() as $b) {
            if ($b['nome'] === $nome) {
                return response()->download($b['percorso'], $b['nome']);
            }
        }
        abort(404);
    }

    /** Salva (o rimuove) il token GitHub nel .env, con il backup del file. */
    public function salvaToken(Request $request, EnvEditor $env): RedirectResponse
    {
        $this->soloSuperAdmin($request);
        $dati = $request->validate(['token' => ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_]*$/']]);

        if (! $env->isWritable()) {
            return redirect()->route('aggiornamenti.index')->withErrors(['update' => 'Il file .env non è scrivibile: imposta UPDATE_GITHUB_TOKEN a mano.']);
        }
        $env->write(['UPDATE_GITHUB_TOKEN' => (string) ($dati['token'] ?? '')]);

        return redirect()->route('aggiornamenti.index')->with('success', filled($dati['token'] ?? null) ? 'Token GitHub salvato.' : 'Token GitHub rimosso.');
    }

    private function rispondi(Request $request, bool $ok, string $messaggio, ?string $logId = null): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['success' => $ok, 'message' => $messaggio, 'log_id' => $logId], $ok ? 200 : 422);
        }

        return $ok
            ? redirect()->route('aggiornamenti.index')->with('success', $messaggio)
            : redirect()->route('aggiornamenti.index')->withErrors(['update' => $messaggio]);
    }

    private function soloSuperAdmin(Request $request): void
    {
        $utente = $request->user();
        abort_unless($utente instanceof Utente && $utente->isSuperAdmin(), 403);
    }
}
