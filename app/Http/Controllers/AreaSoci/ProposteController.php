<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\AdesioneProposta;
use App\Models\Discussione;
use App\Models\Proposta;
use App\Models\Socio;
use App\Services\Struttura\PropostaNonValidaException;
use App\Services\Struttura\ProposteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Proposte dei soci nell'area soci (issue #10): le proposte pubblicate con
 * le adesioni, le proprie (anche in revisione o non ammesse), la
 * presentazione di una nuova proposta e l'adesione.
 */
class ProposteController extends Controller
{
    public function __construct(
        private readonly ProposteService $proposte,
    ) {}

    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $proposte = Proposta::query()
            ->where(fn ($q) => $q->pubbliche()->orWhere('socio_id', $socio->id))
            ->with(['proponente:id,nome,cognome', 'riunione:id,titolo,data_ora'])
            ->withCount('adesioni')
            ->orderByRaw("CASE WHEN stato IN ('pubblicata', 'da_esaminare') THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->paginate(15);

        $mieAdesioni = AdesioneProposta::query()
            ->where('socio_id', $socio->id)
            ->whereIn('proposta_id', $proposte->getCollection()->pluck('id'))
            ->pluck('proposta_id')
            ->flip();

        // Da una propria discussione (issue #13): titolo e testo precompilati
        $daDiscussione = $request->filled('da_discussione')
            ? Discussione::query()->whereKey($request->query('da_discussione'))->where('socio_id', $socio->id)->first()
            : null;

        return view('area-soci.proposte', [
            'socio' => $socio,
            'proposte' => $proposte,
            'precompilata' => $daDiscussione === null ? null : [
                'titolo' => $daDiscussione->titolo,
                'testo' => (string) $daDiscussione->messaggi()->orderBy('created_at')->value('testo'),
            ],
            'mieAdesioni' => $mieAdesioni,
            'soglia' => $this->proposte->soglia($socio->associazione),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dati = $request->validate([
            'titolo' => ['required', 'string', 'max:200'],
            'testo' => ['required', 'string', 'max:5000'],
            'categoria' => ['required', Rule::in(array_keys(Proposta::CATEGORIE))],
            'destinatario' => ['required', Rule::in(array_keys(Proposta::DESTINATARI))],
        ]);

        try {
            $proposta = $this->proposte->presenta($request->user('socio'), $dati);
        } catch (PropostaNonValidaException $e) {
            return redirect()->route('area-soci.proposte')->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.proposte')->with('success', $proposta->stato === Proposta::STATO_IN_REVISIONE
            ? 'Proposta inviata: la segreteria la pubblicherà dopo una verifica.'
            : 'Proposta pubblicata: ora gli altri soci possono aderire.');
    }

    public function aderisci(Request $request, Proposta $proposta): RedirectResponse
    {
        return $this->esegui(fn () => $this->proposte->aderisci($proposta, $request->user('socio')), 'Adesione registrata.');
    }

    public function revoca(Request $request, Proposta $proposta): RedirectResponse
    {
        return $this->esegui(fn () => $this->proposte->revocaAdesione($proposta, $request->user('socio')), 'Adesione revocata.');
    }

    private function esegui(callable $azione, string $successo): RedirectResponse
    {
        try {
            $azione();
        } catch (PropostaNonValidaException $e) {
            return redirect()->route('area-soci.proposte')->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.proposte')->with('success', $successo);
    }
}
