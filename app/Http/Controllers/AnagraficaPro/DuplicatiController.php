<?php

namespace App\Http\Controllers\AnagraficaPro;

use App\Http\Controllers\Controller;
use App\Http\Requests\AnagraficaPro\MergeSociRequest;
use App\Models\Socio;
use App\Services\AnagraficaPro\DuplicatiService;
use App\Services\AnagraficaPro\MergeSociService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Pagina "Possibili duplicati" (f.24 PIANO-VITA-SOCIALE): gruppi di soci
 * con stessa email / CF / nome+cognome+nascita normalizzati e azione
 * "Unisci" assistita (scelta del principale, spostamento dei dati,
 * soft delete del duplicato).
 */
class DuplicatiController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DuplicatiService $duplicati,
        private readonly MergeSociService $mergeService,
    ) {}

    public function index(): View
    {
        $this->authorize('viewAny', Socio::class);

        return view('anagrafica-pro.duplicati.index', [
            'gruppi' => $this->duplicati->gruppi(Socio::currentAssociazioneId()),
            'criteri' => DuplicatiService::CRITERI,
        ]);
    }

    public function merge(MergeSociRequest $request): RedirectResponse
    {
        // Risoluzione col global scope tenant: id cross-tenant → 404
        $principale = Socio::query()->findOrFail($request->validated('principale_id'));
        $duplicato = Socio::query()->findOrFail($request->validated('duplicato_id'));

        // Unire = modificare il principale + eliminare (soft) il duplicato
        $this->authorize('update', $principale);
        $this->authorize('delete', $duplicato);

        $esito = $this->mergeService->merge($principale, $duplicato, $request->user());

        $messaggio = sprintf(
            'Soci uniti: %s spostato in %s (%d tessere, %d quote, %d documenti, %d voci di storico, %d partecipazioni%s). Il duplicato è nel cestino.',
            $duplicato->nome_completo,
            $principale->nome_completo,
            $esito['tessere'],
            $esito['quote'],
            $esito['documenti'],
            $esito['storico'],
            $esito['partecipazioni'],
            $esito['account_spostato'] ? ', account collegato spostato' : ''
        );

        return redirect()
            ->route('soci-duplicati.index')
            ->with('success', $messaggio)
            ->with('warning', $esito['avvisi'] !== [] ? implode(' ', $esito['avvisi']) : null);
    }
}
