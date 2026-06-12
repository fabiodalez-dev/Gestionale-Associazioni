<?php

namespace App\Http\Controllers\EventiVita;

use App\Http\Controllers\Controller;
use App\Models\Evento;
use App\Services\EventiVita\FeedbackEventiService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\View\View;

/**
 * Report feedback post-evento in admin (f.11): media voto,
 * distribuzione 1–5, tasso di risposta e commenti dei partecipanti.
 */
class FeedbackEventoController extends Controller
{
    use AuthorizesRequests;

    public function index(Evento $evento, FeedbackEventiService $feedback): View
    {
        $this->authorize('view', $evento);

        return view('eventi-vita.feedback', [
            'evento' => $evento,
            'stats' => $feedback->statistiche($evento),
        ]);
    }
}
