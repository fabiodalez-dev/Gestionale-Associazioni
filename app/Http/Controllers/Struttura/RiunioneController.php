<?php

namespace App\Http\Controllers\Struttura;

use App\Http\Controllers\Controller;
use App\Http\Requests\Struttura\SalvaRiunioneRequest;
use App\Http\Requests\Struttura\SalvaVotazioneRiunioneRequest;
use App\Models\Carica;
use App\Models\DelegaRiunione;
use App\Models\Elezione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Spazio;
use App\Models\Utente;
use App\Models\Verbale;
use App\Models\VotazioneRiunione;
use App\Models\VotoRiunione;
use App\Notifications\Struttura\ConvocazioneRiunioneNotification;
use App\Services\Struttura\AssembleaService;
use App\Services\Struttura\DelegaNonValidaException;
use App\Services\Struttura\DelegheService;
use App\Services\Struttura\ElezioniService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

/**
 * Riunioni degli organi sociali (f.17-18 del piano "Organizzazione 3"):
 * - CRUD con ordine del giorno strutturato (odg json [{punto, descrizione, maggioranza}]);
 * - direttivo: inviti agli intestatari delle cariche attive (round 1) + utenti
 *   admin, con convocazione email brandizzata (ShouldQueue) e stato presenza;
 * - assemblea dei soci (issue #6): elenco aventi diritto, appello con lettura
 *   della tessera, quorum costitutivo ed esiti calcolati dalla maggioranza;
 *   deleghe registrate dalla segreteria (issue #7);
 * - votazioni per punto sulle riunioni svolte (f.18);
 * - "Genera bozza verbale" → crea un Verbale precompilato con numerazione
 *   automatica (modulo verbali esistente).
 *
 * Governance: policy RiunionePolicy, famiglia di permessi riunioni.*.
 */
class RiunioneController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly AssembleaService $assemblea,
        private readonly DelegheService $deleghe,
    ) {}

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Riunione::class);

        $search = trim((string) $request->query('search', ''));
        $stato = (string) $request->query('stato', 'all');
        $organo = (string) $request->query('organo', 'all');

        $riunioni = Riunione::query()
            ->with(['spazio', 'verbale'])
            ->withCount(['partecipanti', 'votazioni'])
            ->when($search !== '', fn ($q) => $q->where('titolo', 'like', "%{$search}%"))
            ->when(
                $stato !== '' && $stato !== 'all' && in_array($stato, Riunione::STATI, true),
                fn ($q) => $q->where('stato', $stato)
            )
            ->when(
                array_key_exists($organo, Riunione::ORGANI),
                fn ($q) => $q->where('organo', $organo)
            )
            ->orderByDesc('data_ora')
            ->paginate(20)
            ->withQueryString();

        $spazi = Spazio::query()->where('attivo', true)->orderBy('nome')->get(['id', 'nome']);

        return view('struttura.riunioni.index', [
            'riunioni' => $riunioni,
            'search' => $search,
            'stato' => $stato,
            'organo' => $organo,
            'stati' => Riunione::STATI,
            'spazi' => $spazi,
        ]);
    }

    public function show(Riunione $riunione): View
    {
        $this->authorize('view', $riunione);

        $riunione->load([
            'spazio',
            'verbale',
            'votazioni' => fn ($q) => $q->orderBy('punto_indice')->withCount('votanti'),
        ]);

        // In assemblea l'elenco può contare centinaia di soci: si consulta
        // nella pagina dell'appello, qui bastano i numeri del quorum
        if ($riunione->isAssemblea()) {
            $riunione->load([
                'deleghe' => fn ($q) => $q->orderBy('created_at'),
                'elezioni' => fn ($q) => $q->withCount(['candidature' => fn ($c) => $c->valide()])->orderBy('created_at'),
                'deleghe.delegante' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
                'deleghe.delegato' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
            ]);
        } else {
            $riunione->load([
                'partecipanti' => fn ($q) => $q->orderBy('carica_ruolo'),
                'partecipanti.socio' => fn ($q) => $q->withTrashed(),
                'partecipanti.utente',
            ]);
        }

        // Votazioni indicizzate per punto OdG (per il form per-punto, f.18)
        $votazioniPerPunto = $riunione->votazioni->keyBy('punto_indice');

        $spazi = Spazio::query()->where('attivo', true)->orderBy('nome')->get(['id', 'nome']);

        return view('struttura.riunioni.show', [
            'riunione' => $riunione,
            'votazioniPerPunto' => $votazioniPerPunto,
            'quorum' => $riunione->isAssemblea() ? $this->assemblea->quorum($riunione) : null,
            'stati' => Riunione::STATI,
            'esiti' => VotazioneRiunione::ESITI,
            'spazi' => $spazi,
        ]);
    }

    /**
     * Calcola o aggiorna l'elenco degli aventi diritto di un'assemblea (issue #6).
     */
    public function aventiDiritto(Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        if (! $riunione->isAssemblea()) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'L\'elenco degli aventi diritto si calcola solo per le assemblee dei soci.');
        }

        if ($riunione->stato !== Riunione::STATO_PIANIFICATA) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'L\'elenco degli aventi diritto si aggiorna solo prima dello svolgimento dell\'assemblea.');
        }

        $esito = $this->assemblea->generaAventiDiritto($riunione);

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', sprintf(
                'Elenco aventi diritto aggiornato: %d soci in elenco (%d aggiunti, %d rimossi).',
                $esito['totale'],
                $esito['aggiunti'],
                $esito['rimossi'],
            ));
    }

    /**
     * Appello dell'assemblea (issue #6): elenco ricercabile degli aventi
     * diritto con presenza a un clic, lettura della tessera e quorum.
     */
    public function appello(Request $request, Riunione $riunione): View|RedirectResponse
    {
        $this->authorize('view', $riunione);

        if (! $riunione->isAssemblea()) {
            return redirect()->route('riunioni.show', $riunione);
        }

        $search = trim((string) $request->query('search', ''));
        $filtro = (string) $request->query('filtro', 'tutti');

        $partecipanti = RiunionePartecipante::query()
            ->where('riunioni_partecipanti.riunione_id', $riunione->id)
            ->join('soci', 'soci.id', '=', 'riunioni_partecipanti.socio_id')
            ->select('riunioni_partecipanti.*')
            ->with(['socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($w) use ($search) {
                    $w->where('soci.cognome', 'like', "%{$search}%")
                        ->orWhere('soci.nome', 'like', "%{$search}%")
                        ->orWhere('soci.numero_socio', $search);
                });
            })
            ->when($filtro === 'presenti', fn ($q) => $q->where('riunioni_partecipanti.stato', RiunionePartecipante::STATO_CONFERMATO))
            ->when($filtro === 'non_presenti', fn ($q) => $q->where('riunioni_partecipanti.stato', '!=', RiunionePartecipante::STATO_CONFERMATO))
            ->orderBy('soci.cognome')
            ->orderBy('soci.nome')
            ->paginate(50)
            ->withQueryString();

        $sociPagina = $partecipanti->getCollection()->pluck('socio_id');
        $deleghe = DelegaRiunione::query()
            ->where('riunione_id', $riunione->id)
            ->where(fn ($q) => $q->whereIn('delegato_socio_id', $sociPagina)->orWhereIn('delegante_socio_id', $sociPagina))
            ->with([
                'delegante' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
                'delegato' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
            ])
            ->get();

        return view('struttura.riunioni.appello', [
            'riunione' => $riunione,
            'partecipanti' => $partecipanti,
            'delegheRicevute' => $deleghe->groupBy('delegato_socio_id'),
            'delegheDate' => $deleghe->keyBy('delegante_socio_id'),
            'quorum' => $this->assemblea->quorum($riunione),
            'search' => $search,
            'filtro' => $filtro,
        ]);
    }

    /**
     * Registra la presenza in assemblea dal codice letto (QR della tessera,
     * numero tessera o numero socio): pensato per i lettori che "scrivono"
     * il codice nel campo e premono invio.
     */
    public function registraPresenzaCodice(Request $request, Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        abort_unless($riunione->isAssemblea(), 404);

        $codice = trim((string) $request->validate([
            'codice' => ['required', 'string', 'max:255'],
        ])['codice']);

        $partecipante = $this->assemblea->partecipanteDaCodice($riunione, $codice);

        if ($partecipante === null) {
            return redirect()
                ->route('riunioni.appello', $riunione)
                ->with('error', "Nessun avente diritto corrisponde al codice \"{$codice}\".");
        }

        $nome = $partecipante->nomeCompleto();

        if ($partecipante->isPresente()) {
            return redirect()
                ->route('riunioni.appello', $riunione)
                ->with('warning', "{$nome} risulta già presente.");
        }

        $partecipante->update([
            'stato' => RiunionePartecipante::STATO_CONFERMATO,
            'presente_at' => now(),
        ]);

        return redirect()
            ->route('riunioni.appello', $riunione)
            ->with('success', "Presenza registrata: {$nome}.");
    }

    public function store(SalvaRiunioneRequest $request): RedirectResponse
    {
        $riunione = Riunione::create($request->validated());

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Riunione creata con successo!');
    }

    public function update(SalvaRiunioneRequest $request, Riunione $riunione): RedirectResponse
    {
        $riunione->update($request->validated());

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Riunione aggiornata con successo!');
    }

    public function destroy(Riunione $riunione): RedirectResponse
    {
        $this->authorize('delete', $riunione);

        $riunione->delete();

        return redirect()->route('riunioni.index')->with('success', 'Riunione eliminata con successo!');
    }

    /**
     * Invita gli intestatari delle cariche attive (round 1) e gli utenti
     * admin del tenant come partecipanti, poi invia la convocazione email
     * brandizzata (f.17). Idempotente: gli inviti già presenti non vengono
     * duplicati (unique riunione+socio / riunione+utente).
     */
    public function convoca(Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        if ($riunione->stato === Riunione::STATO_ANNULLATA) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'La riunione è annullata: non è possibile inviare la convocazione.');
        }

        if ($riunione->isAssemblea()) {
            return $this->convocaAssemblea($riunione);
        }

        DB::transaction(function () use ($riunione) {
            // Intestatari delle cariche attive (round 1)
            $cariche = Carica::query()->attive()->get();

            foreach ($cariche as $carica) {
                RiunionePartecipante::firstOrCreate(
                    [
                        'riunione_id' => $riunione->id,
                        'socio_id' => $carica->socio_id,
                    ],
                    [
                        'associazione_id' => $riunione->associazione_id,
                        'carica_ruolo' => $carica->ruolo,
                        'stato' => RiunionePartecipante::STATO_INVITATO,
                    ]
                );
            }

            $riunione->forceFill(['convocazione_inviata_at' => now()])->save();
        });

        // Notifica i partecipanti soci (carica) + utenti admin del tenant.
        // withTrashed() serve solo a risolvere la relazione (i nomi storici);
        // i soci soft-deleted vanno comunque esclusi dai destinatari email.
        $riunione->load(['partecipanti.socio' => fn ($q) => $q->withTrashed()]);

        $sociDestinatari = $riunione->partecipanti
            ->pluck('socio')
            ->filter(fn ($socio) => $socio !== null && ! $socio->trashed() && filled($socio->email));

        $utentiAdmin = Utente::query()
            ->where('associazione_id', $riunione->associazione_id)
            ->where('ruolo', Utente::RUOLO_ADMIN_ASSOCIAZIONE)
            ->where('attivo', true)
            ->get();

        if ($sociDestinatari->isNotEmpty()) {
            Notification::send($sociDestinatari, new ConvocazioneRiunioneNotification($riunione));
        }

        if ($utentiAdmin->isNotEmpty()) {
            Notification::send($utentiAdmin, new ConvocazioneRiunioneNotification($riunione));
        }

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Convocazione inviata ai membri dell\'organo direttivo.');
    }

    /**
     * Delega registrata dalla segreteria (modulo cartaceo, issue #7):
     * delegante e delegato si indicano con numero socio, numero tessera
     * o QR della tessera. Il modulo firmato va tra gli allegati della riunione.
     */
    public function registraDelega(Request $request, Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        $dati = $request->validate([
            'delegante' => ['required', 'string', 'max:255'],
            'delegato' => ['required', 'string', 'max:255'],
        ]);

        $sociId = [];
        foreach (['delegante', 'delegato'] as $ruolo) {
            $sociId[$ruolo] = $this->assemblea->socioIdDaCodice($dati[$ruolo]);

            if ($sociId[$ruolo] === null) {
                return redirect()
                    ->route('riunioni.show', $riunione)
                    ->with('error', "Nessun socio corrisponde al codice del {$ruolo} \"{$dati[$ruolo]}\".");
            }
        }

        $soci = Socio::query()->withoutGlobalScope('sede')->whereKey(array_values($sociId))->get()->keyBy('id');

        try {
            $delega = $this->deleghe->registra(
                $riunione,
                $soci[$sociId['delegante']],
                $soci[$sociId['delegato']],
                DelegaRiunione::ORIGINE_SEGRETERIA,
                $request->user(),
            );
        } catch (DelegaNonValidaException $e) {
            return redirect()->route('riunioni.show', $riunione)->with('error', $e->getMessage());
        }

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', "Delega registrata: {$delega->delegante->nome_completo} delega {$delega->delegato->nome_completo}.");
    }

    public function revocaDelega(Riunione $riunione, DelegaRiunione $delega): RedirectResponse
    {
        $this->authorize('update', $riunione);

        abort_unless($delega->riunione_id === $riunione->id, 404);

        try {
            $this->deleghe->revoca($delega, DelegaRiunione::ORIGINE_SEGRETERIA);
        } catch (DelegaNonValidaException $e) {
            return redirect()->route('riunioni.show', $riunione)->with('error', $e->getMessage());
        }

        return redirect()->route('riunioni.show', $riunione)->with('success', 'Delega revocata.');
    }

    /**
     * Convocazione dell'assemblea (issue #6): a tutti gli aventi diritto con
     * email e senza opt-out, come le convocazioni inviate dagli annunci.
     * L'elenco viene calcolato se non esiste ancora.
     */
    private function convocaAssemblea(Riunione $riunione): RedirectResponse
    {
        if ($riunione->aventi_diritto_at === null) {
            $this->assemblea->generaAventiDiritto($riunione);
        }

        $destinatari = Socio::query()
            ->withoutGlobalScope('sede')
            ->whereIn('id', RiunionePartecipante::query()
                ->where('riunione_id', $riunione->id)
                ->whereNotNull('socio_id')
                ->select('socio_id'))
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where('email_opt_out', false)
            ->get();

        if ($destinatari->isNotEmpty()) {
            Notification::send($destinatari, new ConvocazioneRiunioneNotification($riunione));
        }

        $riunione->forceFill(['convocazione_inviata_at' => now()])->save();

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', "Convocazione dell'assemblea inviata a {$destinatari->count()} soci.");
    }

    /**
     * Aggiorna lo stato di presenza di un partecipante (f.17).
     */
    public function aggiornaPresenza(Request $request, Riunione $riunione, RiunionePartecipante $partecipante): RedirectResponse
    {
        $this->authorize('update', $riunione);

        abort_unless($partecipante->riunione_id === $riunione->id, 404);

        $validated = $request->validate([
            'stato' => ['required', 'in:'.implode(',', RiunionePartecipante::STATI)],
        ]);

        $presente = $validated['stato'] === RiunionePartecipante::STATO_CONFERMATO;

        $partecipante->update([
            'stato' => $validated['stato'],
            'presente_at' => $presente ? ($partecipante->presente_at ?? now()) : null,
        ]);

        // Dall'appello si torna all'appello, con ricerca e pagina correnti
        if ($request->input('ritorno') === 'appello' && $riunione->isAssemblea()) {
            return redirect()
                ->route('riunioni.appello', array_merge(
                    ['riunione' => $riunione],
                    $request->only(['search', 'filtro', 'page'])
                ))
                ->with('success', $partecipante->nomeCompleto().($presente ? ' presente.' : ' non presente.'));
        }

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Presenza aggiornata.');
    }

    /**
     * Registra/aggiorna l'esito della votazione su un punto dell'OdG (f.18).
     * Solo per riunioni svolte; un esito per punto (updateOrCreate).
     */
    public function registraVotazione(SalvaVotazioneRiunioneRequest $request, Riunione $riunione): RedirectResponse
    {
        if (! $riunione->isSvolta()) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'Le votazioni si registrano solo sulle riunioni svolte.');
        }

        $dati = $request->validated();

        // Il risultato del voto online non si riscrive a mano (issue #8)
        $esistente = VotazioneRiunione::query()
            ->where('riunione_id', $riunione->id)
            ->where('punto_indice', $dati['punto_indice'])
            ->first();

        if ($esistente?->isOnline()) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'Questo punto è stato votato online: il risultato non si modifica a mano.');
        }

        // In assemblea l'esito discende dai numeri e dalla maggioranza del
        // punto; "rinviato" resta una scelta della presidenza
        if ($riunione->isAssemblea()) {
            $presenti = $this->assemblea->quorum($riunione)['presenti'];
            $votanti = $dati['favorevoli'] + $dati['contrari'] + $dati['astenuti'];

            if ($presenti > 0 && $votanti > $presenti) {
                return redirect()
                    ->route('riunioni.show', $riunione)
                    ->with('error', "I voti espressi ({$votanti}) superano i presenti con diritto di voto ({$presenti}).");
            }

            if ($dati['esito'] !== VotazioneRiunione::ESITO_RINVIATO) {
                $dati['esito'] = $this->assemblea->esito(
                    $riunione->maggioranzaPunto((int) $dati['punto_indice']),
                    $dati['favorevoli'],
                    $dati['contrari'],
                    $presenti,
                );
            }
        }

        VotazioneRiunione::updateOrCreate(
            [
                'riunione_id' => $riunione->id,
                'punto_indice' => $dati['punto_indice'],
            ],
            [
                'associazione_id' => $riunione->associazione_id,
                'esito' => $dati['esito'],
                'favorevoli' => $dati['favorevoli'],
                'contrari' => $dati['contrari'],
                'astenuti' => $dati['astenuti'],
                'note' => $dati['note'] ?? null,
            ]
        );

        return redirect()
            ->route('riunioni.show', $riunione)
            ->with('success', 'Esito della votazione registrato.');
    }

    /**
     * Genera una bozza di Verbale precompilato dalla riunione (f.18):
     * data, presenti, punti dell'OdG ed esiti delle votazioni. Numerazione
     * automatica per anno (modulo verbali esistente). Collega il verbale
     * alla riunione (riunioni.verbale_id) per il link nelle Decisioni.
     */
    public function generaVerbale(Riunione $riunione): RedirectResponse
    {
        $this->authorize('update', $riunione);

        if (! $riunione->isSvolta()) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'La bozza di verbale si genera solo dalle riunioni svolte.');
        }

        if ($riunione->verbale()->exists()) {
            return redirect()
                ->route('riunioni.show', $riunione)
                ->with('warning', 'Una bozza di verbale è già stata generata per questa riunione.');
        }

        $riunione->load([
            'partecipanti' => fn ($q) => $q->where('stato', RiunionePartecipante::STATO_CONFERMATO),
            'partecipanti.socio' => fn ($q) => $q->withTrashed(),
            'partecipanti.utente',
            'votazioni' => fn ($q) => $q->orderBy('punto_indice'),
        ]);

        $verbale = DB::transaction(function () use ($riunione): Verbale {
            $anno = (int) $riunione->data_ora->year;

            $verbale = new Verbale([
                'titolo' => $riunione->titolo,
                'data_riunione' => $riunione->data_ora->toDateString(),
                'tipo_riunione' => $riunione->organoLabel(),
                'presenti' => $riunione->isAssemblea()
                    ? $this->presentiAssemblea($riunione)
                    : $this->elencoPresenti($riunione),
                'ordine_del_giorno' => $this->testoOrdineDelGiorno($riunione),
                'deliberazioni' => $this->testoDeliberazioni($riunione),
            ]);
            $verbale->associazione_id = $riunione->associazione_id;
            $verbale->anno = $anno;
            $verbale->numero = Verbale::prossimoNumero($anno);
            $verbale->save();

            $riunione->forceFill(['verbale_id' => $verbale->id])->save();

            return $verbale;
        });

        return redirect()
            ->route('verbali.index', ['edit' => $verbale->id])
            ->with('success', "Bozza di verbale n. {$verbale->numero_completo} generata dalla riunione. Rivedila e approvala.");
    }

    /**
     * Voto online nel verbale (issue #8): segreto, oppure palese con i nomi
     * dei soci per opzione (i voti espressi in sala restano solo nei totali).
     */
    private function dettaglioVotoOnline(VotazioneRiunione $votazione): string
    {
        if ($votazione->segreta) {
            return 'Votazione a scrutinio segreto con voto elettronico.';
        }

        $perOpzione = VotoRiunione::query()
            ->where('votazione_id', $votazione->id)
            ->with(['socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])
            ->get()
            ->groupBy('opzione');

        $parti = [];
        foreach (VotoRiunione::OPZIONI as $opzione => $etichetta) {
            $nomi = $perOpzione->get($opzione, collect())->map(fn (VotoRiunione $v) => $v->socio?->nome_completo)->filter()->sort()->values();

            if ($nomi->isNotEmpty()) {
                $parti[] = "{$etichetta}: ".$nomi->implode(', ');
            }
        }

        return 'Voto palese online. '.($parti === [] ? 'Nessun voto online.' : implode('; ', $parti).'.');
    }

    /**
     * Presenti dell'assemblea per il verbale: aventi diritto, presenti e
     * quorum costitutivo, seguiti dall'elenco nominativo.
     */
    private function presentiAssemblea(Riunione $riunione): string
    {
        $quorum = $this->assemblea->quorum($riunione);

        // Etichetta della regola senza la spiegazione tra parentesi
        $regola = fn (array $stato): string => mb_strtolower((string) preg_replace('/\s*\(.*\)$/', '', $stato['etichetta']));

        $costituzione = match ($quorum['costituita_in']) {
            'prima' => sprintf('Assemblea validamente costituita in prima convocazione (%s, minimo %d presenti).', $regola($quorum['prima']), $quorum['prima']['minimo']),
            'seconda' => sprintf('Assemblea validamente costituita in seconda convocazione (%s, minimo %d presenti).', $regola($quorum['seconda']), $quorum['seconda']['minimo']),
            default => 'Quorum costitutivo non raggiunto.',
        };

        $righe = [
            "Aventi diritto al voto: {$quorum['aventi_diritto']}.",
            "Presenti con diritto di voto: {$quorum['presenti']} ({$quorum['presenti_in_persona']} di persona, {$quorum['deleghe']} per delega).",
            $costituzione,
            '',
            'Elenco dei presenti:',
            $this->elencoPresenti($riunione),
        ];

        $deleghe = DelegheService::queryValide($riunione)
            ->with([
                'delegante' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
                'delegato' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed(),
            ])
            ->get();

        if ($deleghe->isNotEmpty()) {
            $righe[] = '';
            $righe[] = 'Deleghe:';

            foreach ($deleghe as $delega) {
                $righe[] = "{$delega->delegante?->nome_completo} rappresentato/a da {$delega->delegato?->nome_completo}";
            }
        }

        return implode("\n", $righe);
    }

    /**
     * Elenco dei presenti (partecipanti confermati) per il verbale.
     */
    private function elencoPresenti(Riunione $riunione): string
    {
        $nomi = $riunione->partecipanti
            ->map(function (RiunionePartecipante $p): string {
                $nome = $p->nomeCompleto();
                $ruolo = $p->carica_ruolo;

                return $ruolo ? "{$nome} ({$ruolo})" : $nome;
            })
            ->filter()
            ->values();

        return $nomi->isEmpty() ? 'Nessun partecipante confermato.' : $nomi->implode("\n");
    }

    /**
     * Testo dell'ordine del giorno numerato per il verbale.
     */
    private function testoOrdineDelGiorno(Riunione $riunione): string
    {
        $odg = is_array($riunione->odg) ? $riunione->odg : [];

        if ($odg === []) {
            return 'Nessun punto all\'ordine del giorno.';
        }

        $righe = [];

        foreach ($odg as $i => $punto) {
            $titolo = is_array($punto) ? (string) ($punto['punto'] ?? '') : (string) $punto;
            $descrizione = is_array($punto) ? trim((string) ($punto['descrizione'] ?? '')) : '';
            $riga = ($i + 1).'. '.$titolo;

            if ($descrizione !== '') {
                $riga .= ' — '.$descrizione;
            }

            $righe[] = $riga;
        }

        return implode("\n", $righe);
    }

    /**
     * Testo delle deliberazioni precompilato dagli esiti delle votazioni (f.18).
     */
    private function testoDeliberazioni(Riunione $riunione): string
    {
        $odg = is_array($riunione->odg) ? $riunione->odg : [];

        // Elezioni scrutinate (issue #9)
        $elezioni = $riunione->elezioni()
            ->whereIn('stato', [Elezione::STATO_SCRUTINIO, Elezione::STATO_PROCLAMATA])
            ->orderBy('created_at')
            ->get()
            ->map(fn (Elezione $elezione) => app(ElezioniService::class)->testoVerbale($elezione));

        if ($riunione->votazioni->isEmpty() && $elezioni->isEmpty()) {
            return 'Nessuna votazione registrata.';
        }

        $righe = [];

        foreach ($riunione->votazioni as $votazione) {
            $punto = $odg[$votazione->punto_indice] ?? null;
            $titolo = is_array($punto) ? (string) ($punto['punto'] ?? '') : 'Punto '.($votazione->punto_indice + 1);

            $maggioranza = $riunione->isAssemblea()
                ? match ($riunione->maggioranzaPunto($votazione->punto_indice)) {
                    'assoluta' => ', maggioranza assoluta',
                    'due_terzi' => ', maggioranza dei due terzi',
                    default => ', maggioranza semplice',
                }
            : '';

            $righe[] = sprintf(
                'Punto %d — %s: %s (favorevoli %d, contrari %d, astenuti %d%s).%s',
                $votazione->punto_indice + 1,
                $titolo,
                ucfirst($votazione->esito),
                $votazione->favorevoli,
                $votazione->contrari,
                $votazione->astenuti,
                $maggioranza,
                filled($votazione->note) ? ' '.$votazione->note : ''
            );

            if ($votazione->isOnline()) {
                $righe[] = '   '.$this->dettaglioVotoOnline($votazione);
            }
        }

        return implode("\n", array_merge($righe, $elezioni->all()));
    }
}
