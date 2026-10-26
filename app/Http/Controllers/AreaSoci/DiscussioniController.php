<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\Discussione;
use App\Models\DiscussioneIscrizione;
use App\Models\DiscussioneMessaggio;
use App\Models\DiscussioneSpazio;
use App\Models\Socio;
use App\Services\Comunicazione\DiscussioneNonValidaException;
use App\Services\Comunicazione\DiscussioniService;
use App\Settings\PartecipazioneSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Discussioni tra soci nell'area riservata (issue #13): spazi accessibili,
 * discussioni, risposte, segnalazioni, "segui" e regolamento. Si mostrano
 * solo nome e cognome degli autori, mai i contatti.
 */
class DiscussioniController extends Controller
{
    public function __construct(
        private readonly DiscussioniService $discussioni,
    ) {}

    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $spazi = DiscussioneSpazio::query()
            ->accessibiliA($socio)
            ->withCount('discussioni')
            ->withMax('discussioni', 'ultima_attivita_at')
            ->orderBy('ordine')
            ->orderBy('nome')
            ->get();

        return view('area-soci.discussioni.index', [
            'socio' => $socio,
            'spazi' => $spazi,
            'profilo' => $this->discussioni->profilo($socio),
            'regolamento' => $socio->associazione->impostazioni(PartecipazioneSettings::class)->discussioni_regolamento,
        ]);
    }

    public function spazio(Request $request, DiscussioneSpazio $spazio): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');
        abort_unless($spazio->accessibileA($socio), 404);

        $discussioni = $spazio->discussioni()
            ->with('autore:id,nome,cognome')
            ->withCount(['messaggi' => fn ($q) => $q->visibili()])
            ->orderByDesc('fissata')
            ->orderByDesc('ultima_attivita_at')
            ->paginate(20);

        return view('area-soci.discussioni.spazio', [
            'socio' => $socio,
            'spazio' => $spazio,
            'discussioni' => $discussioni,
            'bloccoScrittura' => $this->discussioni->motivoBloccoScrittura($socio),
        ]);
    }

    public function show(Request $request, Discussione $discussione): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');
        abort_unless($discussione->spazio?->accessibileA($socio), 404);

        $messaggi = $discussione->messaggi()
            ->visibili()
            ->with('autore:id,nome,cognome')
            ->orderBy('created_at')
            ->paginate(30);

        return view('area-soci.discussioni.show', [
            'socio' => $socio,
            'discussione' => $discussione,
            'messaggi' => $messaggi,
            'segue' => DiscussioneIscrizione::query()->where('discussione_id', $discussione->id)->where('socio_id', $socio->id)->exists(),
            'bloccoScrittura' => $this->discussioni->motivoBloccoScrittura($socio),
        ]);
    }

    public function store(Request $request, DiscussioneSpazio $spazio): RedirectResponse
    {
        $dati = $request->validate([
            'titolo' => ['required', 'string', 'max:200'],
            'testo' => ['required', 'string', 'max:5000'],
        ]);

        try {
            $discussione = $this->discussioni->apri($spazio, $request->user('socio'), $dati['titolo'], $dati['testo']);
        } catch (DiscussioneNonValidaException $e) {
            return redirect()->route('area-soci.discussioni.spazio', $spazio)->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.discussioni.show', $discussione)->with('success', 'Discussione aperta.');
    }

    public function rispondi(Request $request, Discussione $discussione): RedirectResponse
    {
        $dati = $request->validate(['testo' => ['required', 'string', 'max:5000']]);

        try {
            $this->discussioni->rispondi($discussione, $request->user('socio'), $dati['testo']);
        } catch (DiscussioneNonValidaException $e) {
            return redirect()->route('area-soci.discussioni.show', $discussione)->withInput()->with('error', $e->getMessage());
        }

        // Ultima pagina, dove compare la risposta
        $pagine = (int) ceil($discussione->messaggi()->visibili()->count() / 30);

        return redirect()->route('area-soci.discussioni.show', ['discussione' => $discussione, 'page' => max(1, $pagine)])->with('success', 'Risposta pubblicata.');
    }

    public function segnala(Request $request, DiscussioneMessaggio $messaggio): RedirectResponse
    {
        $dati = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);

        try {
            $this->discussioni->segnala($messaggio, $request->user('socio'), $dati['motivo'] ?? null);
        } catch (DiscussioneNonValidaException $e) {
            return redirect()->route('area-soci.discussioni.show', $messaggio->discussione_id)->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.discussioni.show', $messaggio->discussione_id)->with('success', 'Grazie: la segnalazione è arrivata alla moderazione.');
    }

    public function segui(Request $request, Discussione $discussione): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');
        abort_unless($discussione->spazio?->accessibileA($socio), 404);

        $this->discussioni->segui($discussione, $socio);

        return redirect()->route('area-soci.discussioni.show', $discussione)->with('success', 'Riceverai un riepilogo dei nuovi messaggi.');
    }

    public function nonSeguire(Request $request, Discussione $discussione): RedirectResponse
    {
        $this->discussioni->smettiDiSeguire($discussione, $request->user('socio'));

        return redirect()->route('area-soci.discussioni.show', $discussione)->with('success', 'Non segui più la discussione.');
    }

    public function accettaRegolamento(Request $request): RedirectResponse
    {
        $this->discussioni->accettaRegolamento($request->user('socio'));

        return redirect()->route('area-soci.discussioni')->with('success', 'Regolamento accettato: ora puoi scrivere.');
    }
}
