<?php

namespace App\Http\Controllers\AreaSoci;

use App\Http\Controllers\Controller;
use App\Models\Candidatura;
use App\Models\DelegaRiunione;
use App\Models\Elezione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\VotanteRiunione;
use App\Models\VotazioneRiunione;
use App\Models\VotoRiunione;
use App\Services\Struttura\DelegaNonValidaException;
use App\Services\Struttura\DelegheService;
use App\Services\Struttura\ElezioneNonValidaException;
use App\Services\Struttura\ElezioniService;
use App\Services\Struttura\VotoAssembleaService;
use App\Services\Struttura\VotoNonValidoException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Assemblee nell'area soci (issues #6, #7 e #8): le assemblee in cui il socio
 * è tra gli aventi diritto, con la possibilità di delegare un altro socio
 * e di revocare la delega fino all'ora della prima convocazione; durante
 * l'assemblea, presenza a distanza e voto online sui punti aperti.
 *
 * Il delegato si indica con numero socio e cognome: l'area soci non mostra
 * l'elenco degli altri soci.
 */
class AssembleeController extends Controller
{
    public function __construct(
        private readonly DelegheService $deleghe,
        private readonly VotoAssembleaService $voto,
        private readonly ElezioniService $elezioni,
    ) {}

    public function index(Request $request): View
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $partecipazioni = RiunionePartecipante::query()
            ->where('socio_id', $socio->id)
            ->whereHas('riunione', fn ($q) => $q
                ->whereIn('organo', [Riunione::ORGANO_ASSEMBLEA_ORDINARIA, Riunione::ORGANO_ASSEMBLEA_STRAORDINARIA])
                ->where('stato', '!=', Riunione::STATO_ANNULLATA)
                ->where('data_ora', '>=', now()->subDays(30)))
            ->with([
                'riunione.spazio',
                'riunione.elezioni.candidature' => fn ($q) => $q->whereNull('ritirata_at'),
                'riunione.elezioni.candidature.socio:id,nome,cognome',
            ])
            ->get()
            ->sortBy(fn (RiunionePartecipante $p) => $p->riunione->data_ora)
            ->values();

        $riunioniIds = $partecipazioni->pluck('riunione_id');

        $deleghe = DelegaRiunione::query()
            ->whereIn('riunione_id', $riunioniIds)
            ->where(fn ($q) => $q->where('delegante_socio_id', $socio->id)->orWhere('delegato_socio_id', $socio->id))
            ->with(['delegante:id,nome,cognome', 'delegato:id,nome,cognome'])
            ->get();

        return view('area-soci.assemblee', [
            'socio' => $socio,
            'assemblee' => $partecipazioni->map(fn (RiunionePartecipante $p) => [
                'riunione' => $p->riunione,
                'partecipante' => $p,
                'delegaData' => $deleghe->first(fn (DelegaRiunione $d) => $d->riunione_id === $p->riunione_id && $d->delegante_socio_id === $socio->id),
                'delegheRicevute' => $deleghe->filter(fn (DelegaRiunione $d) => $d->riunione_id === $p->riunione_id && $d->delegato_socio_id === $socio->id)->values(),
            ]),
        ]);
    }

    public function delega(Request $request, Riunione $riunione): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $dati = $request->validate([
            'numero_socio' => ['required', 'string', 'max:50'],
            'cognome' => ['required', 'string', 'max:100'],
        ]);

        // Numero socio e cognome devono corrispondere: senza il cognome
        // basterebbe provare i numeri per scoprire chi è avente diritto
        $delegato = Socio::query()
            ->withoutGlobalScope('sede')
            ->where('numero_socio', trim($dati['numero_socio']))
            ->get()
            ->first(fn (Socio $s) => mb_strtolower(trim((string) $s->cognome)) === mb_strtolower(trim($dati['cognome'])));

        if ($delegato === null) {
            return $this->indietro('error', 'Nessun socio corrisponde a numero socio e cognome indicati.');
        }

        try {
            $this->deleghe->registra($riunione, $socio, $delegato, DelegaRiunione::ORIGINE_AREA_SOCI);
        } catch (DelegaNonValidaException $e) {
            return $this->indietro('error', $e->getMessage());
        }

        return $this->indietro('success', "Delega a {$delegato->nome_completo} registrata. Ti abbiamo inviato una conferma via email.");
    }

    public function revoca(Request $request, Riunione $riunione): RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $delega = DelegaRiunione::query()
            ->where('riunione_id', $riunione->id)
            ->where('delegante_socio_id', $socio->id)
            ->first();

        if ($delega === null) {
            return $this->indietro('warning', 'Non hai deleghe da revocare per questa assemblea.');
        }

        try {
            $this->deleghe->revoca($delega, DelegaRiunione::ORIGINE_AREA_SOCI);
        } catch (DelegaNonValidaException $e) {
            return $this->indietro('error', $e->getMessage());
        }

        return $this->indietro('success', 'Delega revocata.');
    }

    private function indietro(string $tipo, string $messaggio): RedirectResponse
    {
        return redirect()->route('area-soci.assemblee')->with($tipo, $messaggio);
    }

    /**
     * Assemblea in corso: presenza, voti aperti con i rappresentati del
     * socio e risultati dei punti già votati.
     */
    public function show(Request $request, Riunione $riunione): View|RedirectResponse
    {
        /** @var Socio $socio */
        $socio = $request->user('socio');

        $partecipante = RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->where('socio_id', $socio->id)
            ->first();

        if ($partecipante === null || ! $riunione->isAssemblea()) {
            return $this->indietro('warning', 'Non risulti tra gli aventi diritto di questa assemblea.');
        }

        $votazioni = VotazioneRiunione::query()
            ->where('riunione_id', $riunione->id)
            ->whereNotNull('aperta_at')
            ->orderBy('punto_indice')
            ->get();

        $rappresentati = $this->voto->rappresentati($riunione, $socio);

        // Elezioni con il voto aperto: scheda per i rappresentati che non hanno votato
        $elezioni = $riunione->elezioni()
            ->whereIn('stato', [Elezione::STATO_VOTO, Elezione::STATO_SCRUTINIO, Elezione::STATO_PROCLAMATA])
            ->with(['candidature' => fn ($q) => $q->valide()->orderBy('created_at'), 'candidature.socio:id,nome,cognome'])
            ->get()
            ->map(fn (Elezione $e) => [
                'elezione' => $e,
                'daVotare' => $e->stato === Elezione::STATO_VOTO ? $this->elezioni->daVotare($e, $socio) : collect(),
            ]);

        $giaVotati = VotanteRiunione::query()
            ->whereIn('votazione_id', $votazioni->pluck('id'))
            ->whereIn('rappresentato_socio_id', $rappresentati->keys())
            ->get(['votazione_id', 'rappresentato_socio_id'])
            ->groupBy('votazione_id')
            ->map(fn ($righe) => $righe->pluck('rappresentato_socio_id')->all());

        return view('area-soci.assemblea', [
            'socio' => $socio,
            'riunione' => $riunione,
            'partecipante' => $partecipante,
            'votazioni' => $votazioni,
            'rappresentati' => $rappresentati,
            'giaVotati' => $giaVotati,
            'elezioni' => $elezioni,
            'opzioni' => VotoRiunione::OPZIONI,
        ]);
    }

    public function presenza(Request $request, Riunione $riunione): RedirectResponse
    {
        try {
            $this->voto->partecipaDaRemoto($riunione, $request->user('socio'));
        } catch (VotoNonValidoException $e) {
            return redirect()->route('area-soci.assemblee.show', $riunione)->with('error', $e->getMessage());
        }

        return redirect()->route('area-soci.assemblee.show', $riunione)->with('success', 'La tua presenza è registrata.');
    }

    public function vota(Request $request, Riunione $riunione, VotazioneRiunione $votazione): RedirectResponse
    {
        abort_unless($votazione->riunione_id === $riunione->id, 404);

        $dati = $request->validate([
            'voti' => ['required', 'array'],
            'voti.*' => ['required', 'string'],
        ]);

        try {
            $registrati = $this->voto->vota($votazione, $request->user('socio'), $dati['voti']);
        } catch (VotoNonValidoException $e) {
            return redirect()->route('area-soci.assemblee.show', $riunione)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('area-soci.assemblee.show', $riunione)
            ->with('success', $registrati === 1 ? 'Voto registrato.' : "{$registrati} voti registrati.");
    }

    /**
     * Autocandidatura (issue #9) nel periodo delle candidature.
     */
    public function candida(Request $request, Elezione $elezione): RedirectResponse
    {
        $dati = $request->validate(['presentazione' => ['nullable', 'string', 'max:2000']]);

        try {
            $this->elezioni->candida($elezione, $request->user('socio'), 'area_soci', $dati['presentazione'] ?? null);
        } catch (ElezioneNonValidaException $e) {
            return $this->indietro('error', $e->getMessage());
        }

        return $this->indietro('success', 'La tua candidatura è registrata.');
    }

    /**
     * Il socio accetta la candidatura proposta dalla segreteria.
     */
    public function accettaCandidatura(Request $request, Elezione $elezione): RedirectResponse
    {
        return $this->suCandidatura($request, $elezione, fn (Candidatura $c) => $this->elezioni->accetta($c), 'Candidatura accettata.');
    }

    public function ritiraCandidatura(Request $request, Elezione $elezione): RedirectResponse
    {
        return $this->suCandidatura($request, $elezione, fn (Candidatura $c) => $this->elezioni->ritira($c), 'Candidatura ritirata.');
    }

    public function votaElezione(Request $request, Elezione $elezione): RedirectResponse
    {
        $dati = $request->validate([
            'schede' => ['required', 'array'],
            'schede.*' => ['nullable', 'array'],
            'schede.*.*' => ['string'],
        ]);

        // Una scheda senza preferenze arriva come chiave assente: il socio
        // conferma esplicitamente le schede bianche con "bianca"
        $schede = array_map(fn ($preferenze) => array_values(array_filter((array) $preferenze, fn ($p) => $p !== 'bianca')), $dati['schede']);

        try {
            $votate = $this->elezioni->vota($elezione, $request->user('socio'), $schede);
        } catch (ElezioneNonValidaException $e) {
            return redirect()->route('area-soci.assemblee.show', $elezione->riunione_id)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('area-soci.assemblee.show', $elezione->riunione_id)
            ->with('success', $votate === 1 ? 'Scheda votata.' : "{$votate} schede votate.");
    }

    private function suCandidatura(Request $request, Elezione $elezione, callable $azione, string $successo): RedirectResponse
    {
        $candidatura = Candidatura::query()
            ->where('elezione_id', $elezione->id)
            ->where('socio_id', $request->user('socio')->id)
            ->whereNull('ritirata_at')
            ->first();

        if ($candidatura === null) {
            return $this->indietro('warning', 'Non hai candidature per questa elezione.');
        }

        try {
            $azione($candidatura);
        } catch (ElezioneNonValidaException $e) {
            return $this->indietro('error', $e->getMessage());
        }

        return $this->indietro('success', $successo);
    }
}
