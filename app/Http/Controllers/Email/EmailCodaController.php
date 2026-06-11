<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\EmailQueue;
use App\Services\Email\EmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmailCodaController extends Controller
{
    public function __construct(private readonly EmailService $emailService) {}

    public function index(): View
    {
        Gate::authorize('viewAny', EmailQueue::class);

        $byStatus = EmailQueue::query()
            ->selectRaw('status, COUNT(*) as totale')
            ->groupBy('status')
            ->pluck('totale', 'status');

        $stats = [
            'pending' => (int) ($byStatus[EmailQueue::STATUS_PENDING] ?? 0),
            'sending' => (int) ($byStatus[EmailQueue::STATUS_SENDING] ?? 0),
            'failed' => (int) ($byStatus[EmailQueue::STATUS_FAILED] ?? 0),
            'inviate_oggi' => EmailQueue::query()
                ->where('status', EmailQueue::STATUS_SENT)
                ->whereDate('sent_at', today())
                ->count(),
        ];

        // Monitoraggio motore queue Laravel (miglioramento 18)
        $engine = [
            'jobs' => (int) DB::table('jobs')->count(),
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
        ];

        $items = EmailQueue::query()
            ->with('socio:id,associazione_id,nome,cognome')
            ->orderByRaw("CASE status WHEN 'sending' THEN 0 WHEN 'pending' THEN 1 WHEN 'failed' THEN 2 WHEN 'sent' THEN 3 ELSE 4 END")
            ->orderBy('priority')
            ->orderByDesc('created_at')
            ->paginate(25);

        return view('email.coda', [
            'stats' => $stats,
            'engine' => $engine,
            'items' => $items,
        ]);
    }

    /**
     * Elaborazione manuale della coda (AJAX): processa fino a 50 email del
     * tenant corrente e ritorna {success, sent, failed, remaining}.
     */
    public function process(): JsonResponse
    {
        Gate::authorize('process', EmailQueue::class);

        $associazione = Associazione::query()->findOrFail(session('associazione_id'));

        if (! $this->emailService->isConfigured($associazione)) {
            return response()->json(['success' => false, 'error' => 'SMTP non configurato.']);
        }

        $result = $this->emailService->processQueue($associazione, 50, auth()->id());

        return response()->json([
            'success' => true,
            'sent' => $result['sent'],
            'failed' => $result['failed'],
            'remaining' => $result['remaining'],
        ]);
    }

    /**
     * Annulla una singola email pending (verifica tenant + stato corrente).
     */
    public function cancel(EmailQueue $item): RedirectResponse
    {
        Gate::authorize('cancel', $item);

        $updated = EmailQueue::query()
            ->whereKey($item->id)
            ->where('status', EmailQueue::STATUS_PENDING)
            ->update(['status' => EmailQueue::STATUS_CANCELLED]);

        return redirect()
            ->route('email-coda.index')
            ->with($updated ? 'success' : 'warning', $updated ? 'Email annullata.' : 'L\'email non è più annullabile.');
    }

    /**
     * Riprova una email failed: reset completo dei tentativi (come il legacy).
     */
    public function retry(EmailQueue $item): RedirectResponse
    {
        Gate::authorize('retry', $item);

        $updated = EmailQueue::query()
            ->whereKey($item->id)
            ->where('status', EmailQueue::STATUS_FAILED)
            ->update([
                'status' => EmailQueue::STATUS_PENDING,
                'attempts' => 0,
                'error_message' => null,
                'next_attempt_at' => null,
            ]);

        return redirect()
            ->route('email-coda.index')
            ->with($updated ? 'success' : 'warning', $updated ? 'Email rimessa in coda.' : 'Solo le email fallite possono essere ritentate.');
    }

    /**
     * Annulla tutte le email pending del tenant.
     */
    public function cancelPending(): RedirectResponse
    {
        Gate::authorize('cancel', new EmailQueue);

        $count = EmailQueue::query()
            ->where('status', EmailQueue::STATUS_PENDING)
            ->update(['status' => EmailQueue::STATUS_CANCELLED]);

        return redirect()
            ->route('email-coda.index')
            ->with('success', $count.' email pendenti annullate.');
    }
}
