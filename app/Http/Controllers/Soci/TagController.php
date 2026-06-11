<?php

namespace App\Http\Controllers\Soci;

use App\Http\Controllers\Controller;
use App\Http\Requests\Soci\TagRequest;
use App\Models\Tag;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Configurazione tag soci (spec soci §6) — solo admin.
 */
class TagController extends Controller
{
    use AuthorizesRequests;

    public function index(): View
    {
        $this->authorize('viewAny', Tag::class);

        return view('soci.tags.index', [
            'tags' => Tag::query()->withCount('soci')->orderBy('nome_tag')->get(),
        ]);
    }

    public function store(TagRequest $request): RedirectResponse
    {
        $this->authorize('create', Tag::class);

        Tag::create($request->validated());

        return redirect()->route('config-tags.index')->with('success', 'Tag creato.');
    }

    public function update(TagRequest $request, Tag $tag): RedirectResponse
    {
        $this->authorize('update', $tag);

        $tag->update($request->validated());

        return redirect()->route('config-tags.index')->with('success', 'Tag aggiornato.');
    }

    public function destroy(Tag $tag): RedirectResponse
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        return redirect()->route('config-tags.index')->with('success', 'Tag eliminato.');
    }
}
