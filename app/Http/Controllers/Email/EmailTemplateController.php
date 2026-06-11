<?php

namespace App\Http\Controllers\Email;

use App\Http\Controllers\Controller;
use App\Http\Requests\Email\UpdateEmailTemplateRequest;
use App\Models\EmailTemplate;
use App\Services\Email\DefaultEmailTemplates;
use App\Services\Email\EmailHtmlSanitizer;
use App\Services\Email\EmailPlaceholders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class EmailTemplateController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', EmailTemplate::class);

        $associazioneId = (string) session('associazione_id');

        // Seed automatico dei default alla prima apertura della pagina
        if (! EmailTemplate::query()->exists()) {
            DefaultEmailTemplates::seedFor($associazioneId);
        }

        $templates = EmailTemplate::query()
            ->get()
            ->sortBy(function (EmailTemplate $t) {
                $pos = array_search($t->codice, DefaultEmailTemplates::ORDER, true);

                return $pos === false ? PHP_INT_MAX : $pos;
            })
            ->values();

        // Template corrente da ?t=<codice>, default: primo della lista
        $requested = (string) $request->query('t', '');
        $current = $templates->firstWhere('codice', $requested) ?? $templates->first();

        return view('email.templates', [
            'templates' => $templates,
            'current' => $current,
            'placeholders' => EmailPlaceholders::forCodice($current?->codice),
        ]);
    }

    public function update(UpdateEmailTemplateRequest $request, string $codice, EmailHtmlSanitizer $sanitizer): RedirectResponse
    {
        $template = EmailTemplate::query()
            ->where('codice', $codice)
            ->firstOrFail();

        Gate::authorize('update', $template);

        $data = $request->validated();

        $template->update([
            'oggetto' => $data['oggetto'],
            // Miglioramento 7: HTML sanitizzato centralmente con purifier
            'corpo_html' => $sanitizer->clean($data['corpo_html']),
            'corpo_json' => $data['corpo_json'] ?? null,
            'attivo' => $request->boolean('attivo'),
        ]);

        return redirect()
            ->route('email-templates.index', ['t' => $template->codice])
            ->with('success', 'Template "'.$template->nome.'" salvato con successo.');
    }
}
