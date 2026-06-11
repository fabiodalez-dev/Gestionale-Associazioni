<?php

namespace App\Http\Controllers\Tessere;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tessere\SalvaTesseraTemplateRequest;
use App\Models\Tessera;
use App\Models\TesseraTemplate;
use App\Models\TipoSocio;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Gestione dei template del testo tessera per tipo socio
 * (tipo_socio_id NULL = template generico dell'associazione).
 */
class TesseraTemplateController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', Tessera::class);

        $templates = TesseraTemplate::query()
            ->with('tipoSocio')
            ->orderByRaw('(tipo_socio_id is null) desc')
            ->orderBy('titolo')
            ->get();

        $tipiSocio = TipoSocio::query()->orderBy('nome')->get(['id', 'nome']);

        return view('tessere.templates', [
            'templates' => $templates,
            'tipiSocio' => $tipiSocio,
        ]);
    }

    public function store(SalvaTesseraTemplateRequest $request): RedirectResponse
    {
        TesseraTemplate::create([
            'tipo_socio_id' => $request->validated('tipo_socio_id'),
            'titolo' => $request->validated('titolo') ?: 'Template Tessera',
            'contenuto' => $request->validated('contenuto'),
            'attivo' => (bool) $request->validated('attivo'),
        ]);

        return redirect()
            ->route('tessere.templates.index')
            ->with('success', 'Template tessera creato.');
    }

    public function update(SalvaTesseraTemplateRequest $request, TesseraTemplate $template): RedirectResponse
    {
        $template->update([
            'tipo_socio_id' => $request->validated('tipo_socio_id'),
            'titolo' => $request->validated('titolo') ?: 'Template Tessera',
            'contenuto' => $request->validated('contenuto'),
            'attivo' => (bool) $request->validated('attivo'),
        ]);

        return redirect()
            ->route('tessere.templates.index')
            ->with('success', 'Template tessera aggiornato.');
    }

    public function destroy(TesseraTemplate $template): RedirectResponse
    {
        $user = auth()->user();
        abort_unless(
            $user !== null && (
                (method_exists($user, 'isSuperAdmin') && $user->isSuperAdmin())
                || $user->can('tessere.delete')
            ),
            403
        );

        $template->delete();

        return redirect()
            ->route('tessere.templates.index')
            ->with('success', 'Template tessera eliminato.');
    }
}
