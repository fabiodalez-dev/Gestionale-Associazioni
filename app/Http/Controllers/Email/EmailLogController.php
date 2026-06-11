<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Models\EmailLog;
use App\Models\EmailQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmailLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', EmailLog::class);

        $query = EmailLog::query()->with([
            'socio:id,associazione_id,nome,cognome',
            'sentBy:id,nome,cognome',
        ]);

        // Whitelist rigida (altri valori ignorati, come il legacy)
        $status = $request->query('status');
        if (in_array($status, [EmailLog::STATUS_SENT, EmailLog::STATUS_FAILED], true)) {
            $query->where('status', $status);
        }

        if ($request->filled('template')) {
            $query->where('template_codice', $request->query('template'));
        }

        if ($request->filled('q')) {
            $q = (string) $request->query('q');
            $query->where(function ($builder) use ($q) {
                $builder->where('to_email', 'like', "%{$q}%")
                    ->orWhere('to_name', 'like', "%{$q}%")
                    ->orWhere('subject', 'like', "%{$q}%");
            });
        }

        if ($request->filled('from')) {
            $query->whereDate('sent_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->whereDate('sent_at', '<=', $request->query('to'));
        }

        $logs = $query->orderByDesc('sent_at')->paginate(25)->withQueryString();

        $templateCodes = EmailLog::query()
            ->whereNotNull('template_codice')
            ->distinct()
            ->orderBy('template_codice')
            ->pluck('template_codice');

        return view('email.log', [
            'logs' => $logs,
            'templateCodes' => $templateCodes,
            'filters' => $request->only(['status', 'template', 'q', 'from', 'to']),
        ]);
    }

    /**
     * Dettaglio email (modale AJAX): metadati + body_html.
     * Il body è salvato direttamente nel log (miglioria §11); per i record
     * importati dal legacy si ricade sul JOIN euristico con la coda.
     */
    public function show(EmailLog $log): JsonResponse
    {
        Gate::authorize('view', $log);

        $bodyHtml = $log->body_html;

        if ($bodyHtml === null && $log->batch_id !== null) {
            $bodyHtml = EmailQueue::query()
                ->where('batch_id', $log->batch_id)
                ->where('to_email', $log->to_email)
                ->value('body_html');
        }

        return response()->json([
            'success' => true,
            'log' => [
                'id' => $log->id,
                'to_email' => $log->to_email,
                'to_name' => $log->to_name,
                'subject' => $log->subject,
                'template_codice' => $log->template_codice,
                'status' => $log->status,
                'error_message' => $log->error_message,
                'sent_at' => $log->sent_at?->format('d/m/Y H:i'),
                'sent_by' => $log->sentBy->nome_completo ?? 'Sistema',
                'body_html' => $bodyHtml,
            ],
        ]);
    }
}
