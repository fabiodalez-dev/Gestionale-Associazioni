<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Utente;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Code e job falliti (solo super_admin): elenco con filtro per coda,
 * dettaglio dell'eccezione, riprova ed eliminazione, singoli o tutti.
 * Riprova ed eliminazione passano dai comandi queue:retry / queue:forget /
 * queue:flush, che rispettano il failed job provider configurato.
 */
class JobFallitiController extends Controller
{
    public function index(Request $request): View
    {
        $this->soloSuperAdmin($request);

        $coda = (string) $request->query('coda', '');
        $query = DB::table($this->tabella())
            ->when($coda !== '', fn ($q) => $q->where('queue', $coda))
            ->orderByDesc('failed_at');

        $job = $query->paginate(25, ['id', 'uuid', 'connection', 'queue', 'payload', 'exception', 'failed_at'])
            ->withQueryString()
            ->through(fn (object $riga) => $this->riepilogo($riga));

        return view('job-falliti.index', [
            'job' => $job,
            'code' => DB::table($this->tabella())->distinct()->orderBy('queue')->pluck('queue'),
            'coda' => $coda,
            'ultime24h' => DB::table($this->tabella())->where('failed_at', '>=', now()->subDay())->count(),
            'inAttesa' => $this->jobInAttesa(),
            'destinatari' => config('failed-job-monitor.mail.to', []),
        ]);
    }

    public function show(Request $request, string $uuid): View
    {
        $this->soloSuperAdmin($request);

        $riga = DB::table($this->tabella())->where('uuid', $uuid)->first();
        abort_if($riga === null, 404);

        return view('job-falliti.show', ['job' => $this->riepilogo($riga)]);
    }

    public function retry(Request $request, string $uuid): RedirectResponse
    {
        $this->soloSuperAdmin($request);
        abort_unless(DB::table($this->tabella())->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return redirect()->route('job-falliti.index')->with('success', 'Job rimesso in coda.');
    }

    public function destroy(Request $request, string $uuid): RedirectResponse
    {
        $this->soloSuperAdmin($request);
        abort_unless(DB::table($this->tabella())->where('uuid', $uuid)->exists(), 404);

        Artisan::call('queue:forget', ['id' => $uuid]);

        return redirect()->route('job-falliti.index')->with('success', 'Job fallito eliminato.');
    }

    public function retryAll(Request $request): RedirectResponse
    {
        $this->soloSuperAdmin($request);
        $numero = DB::table($this->tabella())->count();

        Artisan::call('queue:retry', ['id' => ['all']]);

        return redirect()->route('job-falliti.index')->with('success', "{$numero} job rimessi in coda.");
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $this->soloSuperAdmin($request);
        $numero = DB::table($this->tabella())->count();

        Artisan::call('queue:flush');

        return redirect()->route('job-falliti.index')->with('success', "{$numero} job falliti eliminati.");
    }

    /**
     * @return array<string, mixed>
     */
    private function riepilogo(object $riga): array
    {
        $payload = json_decode((string) $riga->payload, true) ?: [];
        $eccezione = (string) $riga->exception;

        return [
            'uuid' => $riga->uuid,
            'nome' => (string) ($payload['displayName'] ?? 'Job sconosciuto'),
            'connessione' => $riga->connection,
            'coda' => $riga->queue,
            'tentativi' => $payload['attempts'] ?? null,
            'max_tentativi' => $payload['maxTries'] ?? null,
            'messaggio' => strtok($eccezione, "\n") ?: '',
            'eccezione' => $eccezione,
            'fallito_at' => Carbon::parse($riga->failed_at),
        ];
    }

    private function jobInAttesa(): ?int
    {
        if (config('queue.default') !== 'database') {
            return null;
        }

        return DB::table((string) config('queue.connections.database.table', 'jobs'))->count();
    }

    private function tabella(): string
    {
        return (string) config('queue.failed.table', 'failed_jobs');
    }

    private function soloSuperAdmin(Request $request): void
    {
        $utente = $request->user();
        abort_unless($utente instanceof Utente && $utente->isSuperAdmin(), 403);
    }
}
