<?php

namespace App\Http\Controllers\Comunicazione;

use App\Http\Controllers\Controller;
use App\Models\Associazione;
use App\Models\Discussione;
use App\Models\DiscussioneMessaggio;
use App\Models\DiscussioneProfilo;
use App\Models\DiscussioneSpazio;
use App\Models\Socio;
use App\Models\Tag;
use App\Services\Comunicazione\DiscussioniService;
use App\Settings\PartecipazioneSettings;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Moderazione delle discussioni tra soci (issue #13): spazi, segnalazioni
 * aperte, messaggi nascosti e ripristinati, discussioni chiuse o fissate,
 * soci sospesi dalla scrittura, regolamento.
 * Governance: DiscussioneSpazioPolicy, famiglia di permessi comunicazione.*.
 */
class DiscussioniModerazioneController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DiscussioniService $discussioni,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', DiscussioneSpazio::class);

        $segnalazioni = DiscussioneMessaggio::query()
            ->whereHas('segnalazioni', fn ($q) => $q->whereNull('gestita_at'))
            ->with([
                'autore' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
                'discussione:id,titolo',
                'segnalazioni' => fn ($q) => $q->whereNull('gestita_at'),
            ])
            ->latest()
            ->get();

        return view('comunicazione.discussioni.index', [
            'spazi' => DiscussioneSpazio::query()->with('tag')->withCount('discussioni')->orderBy('archiviato')->orderBy('ordine')->orderBy('nome')->get(),
            'segnalazioni' => $segnalazioni,
            'recenti' => Discussione::query()->with(['spazio:id,nome', 'autore:id,nome,cognome'])->orderByDesc('ultima_attivita_at')->limit(15)->get(),
            'sospesi' => DiscussioneProfilo::query()->whereNotNull('sospeso_at')->with(['socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])->get(),
            'tags' => Tag::query()->orderBy('nome_tag')->get(['id', 'nome_tag']),
            'regolamento' => $this->associazione($request)->impostazioni(PartecipazioneSettings::class)->discussioni_regolamento,
        ]);
    }

    public function storeSpazio(Request $request): RedirectResponse
    {
        $this->authorize('create', DiscussioneSpazio::class);

        DiscussioneSpazio::create($this->datiSpazio($request));

        return redirect()->route('discussioni.index')->with('success', 'Spazio creato.');
    }

    public function updateSpazio(Request $request, DiscussioneSpazio $spazio): RedirectResponse
    {
        $this->authorize('update', $spazio);

        $spazio->update($this->datiSpazio($request) + ['archiviato' => $request->boolean('archiviato')]);

        return redirect()->route('discussioni.index')->with('success', 'Spazio aggiornato.');
    }

    public function show(Discussione $discussione): View
    {
        $this->authorize('update', $discussione->spazio);

        $discussione->load([
            'spazio',
            'messaggi' => fn ($q) => $q->orderBy('created_at')->withCount(['segnalazioni' => fn ($s) => $s->whereNull('gestita_at')]),
            'messaggi.autore' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
        ]);

        return view('comunicazione.discussioni.show', ['discussione' => $discussione]);
    }

    public function nascondi(Request $request, DiscussioneMessaggio $messaggio): RedirectResponse
    {
        $this->authorize('update', $messaggio->discussione->spazio);

        $this->discussioni->nascondi($messaggio, $request->user());

        return back()->with('success', 'Messaggio nascosto ai soci.');
    }

    public function ripristina(DiscussioneMessaggio $messaggio): RedirectResponse
    {
        $this->authorize('update', $messaggio->discussione->spazio);

        $this->discussioni->ripristina($messaggio);

        return back()->with('success', 'Messaggio di nuovo visibile.');
    }

    public function archiviaSegnalazioni(DiscussioneMessaggio $messaggio): RedirectResponse
    {
        $this->authorize('update', $messaggio->discussione->spazio);

        $this->discussioni->chiudiSegnalazioni($messaggio);

        return back()->with('success', 'Segnalazione archiviata: il messaggio resta visibile.');
    }

    public function aggiornaDiscussione(Request $request, Discussione $discussione): RedirectResponse
    {
        $this->authorize('update', $discussione->spazio);

        $dati = $request->validate([
            'chiusa' => ['sometimes', 'boolean'],
            'fissata' => ['sometimes', 'boolean'],
        ]);

        $discussione->update($dati);

        return back()->with('success', 'Discussione aggiornata.');
    }

    public function sospendi(Request $request, Socio $socio): RedirectResponse
    {
        $this->authorize('create', DiscussioneSpazio::class);

        $dati = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $this->discussioni->sospendi($socio, $dati['motivo'] ?? null);

        return back()->with('success', "{$socio->nome_completo} non può più scrivere nelle discussioni.");
    }

    public function riattiva(Socio $socio): RedirectResponse
    {
        $this->authorize('create', DiscussioneSpazio::class);

        $this->discussioni->riattiva($socio);

        return back()->with('success', "{$socio->nome_completo} può di nuovo scrivere.");
    }

    public function regolamento(Request $request): RedirectResponse
    {
        $this->authorize('create', DiscussioneSpazio::class);

        $dati = $request->validate(['discussioni_regolamento' => ['required', 'string', 'max:10000']]);
        $this->associazione($request)->impostazioni(PartecipazioneSettings::class)->aggiorna($dati);

        return redirect()->route('discussioni.index')->with('success', 'Regolamento aggiornato.');
    }

    /**
     * @return array<string, mixed>
     */
    private function datiSpazio(Request $request): array
    {
        $dati = $request->validate([
            'nome' => ['required', 'string', 'max:100'],
            'descrizione' => ['nullable', 'string', 'max:1000'],
            'accesso' => ['required', Rule::in(array_keys(DiscussioneSpazio::ACCESSI))],
            'tag_id' => ['nullable', 'required_if:accesso,tag', 'uuid', Rule::exists('tags', 'id')->where('associazione_id', session('associazione_id') ?? $request->user()->associazione_id)],
            'ordine' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);

        return [
            'nome' => trim($dati['nome']),
            'descrizione' => $dati['descrizione'] ?? null,
            'accesso' => $dati['accesso'],
            'tag_id' => $dati['accesso'] === 'tag' ? $dati['tag_id'] : null,
            'ordine' => (int) ($dati['ordine'] ?? 0),
        ];
    }

    private function associazione(Request $request): Associazione
    {
        return Associazione::query()->findOrFail(session('associazione_id') ?? $request->user()->associazione_id);
    }
}
