<?php

namespace App\Services\Struttura;

use App\Models\Candidatura;
use App\Models\Carica;
use App\Models\Elezione;
use App\Models\RiunionePartecipante;
use App\Models\SchedaElezione;
use App\Models\Socio;
use App\Models\VotanteElezione;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Elezioni e rinnovo delle cariche (issue #9).
 *
 * - Candidature: dall'area soci nel periodo previsto (valgono subito) o dalla
 *   segreteria (valgono dopo l'accettazione del candidato o del modulo).
 *   Requisiti: socio attivo, maggiorenne, con l'anzianità minima e, se
 *   l'elenco è già calcolato, avente diritto al voto nell'assemblea.
 * - Voto: scheda segreta con al massimo N preferenze, per sé e per i
 *   deleganti (stesse regole del voto online, issue #8).
 * - Scrutinio: preferenze online + preferenze dalle schede cartacee;
 *   classifica con la regola di parità dell'elezione; eletti i primi
 *   "posti" con almeno una preferenza.
 * - Proclamazione: chiude le cariche attive dei ruoli dell'organo e crea
 *   la carica degli eletti per la durata del mandato.
 */
class ElezioniService
{
    public function __construct(
        private readonly AssembleaService $assemblea,
        private readonly VotoAssembleaService $voto,
    ) {}

    public function candida(Elezione $elezione, Socio $socio, string $origine, ?string $presentazione = null, bool $accettata = false): Candidatura
    {
        $daAreaSoci = $origine === 'area_soci';

        if ($elezione->stato !== Elezione::STATO_CANDIDATURE || ($daAreaSoci && ! $elezione->candidatureAperte())) {
            throw new ElezioneNonValidaException('Le candidature per questa elezione non sono aperte.');
        }

        $this->verificaRequisiti($elezione, $socio);

        $esistente = Candidatura::query()->where('elezione_id', $elezione->id)->where('socio_id', $socio->id)->first();

        if ($esistente !== null && $esistente->ritirata_at === null) {
            throw new ElezioneNonValidaException("{$socio->nome_completo} è già candidato/a.");
        }

        $dati = [
            'presentazione' => filled($presentazione) ? trim($presentazione) : null,
            'origine' => $origine,
            'accettata_at' => ($daAreaSoci || $accettata) ? now() : null,
            'ritirata_at' => null,
        ];

        if ($esistente !== null) {
            $esistente->update($dati);

            return $esistente;
        }

        try {
            return Candidatura::create($dati + [
                'associazione_id' => $elezione->associazione_id,
                'elezione_id' => $elezione->id,
                'socio_id' => $socio->id,
            ]);
        } catch (UniqueConstraintViolationException) {
            throw new ElezioneNonValidaException("{$socio->nome_completo} è già candidato/a.");
        }
    }

    public function accetta(Candidatura $candidatura): void
    {
        if ($candidatura->elezione->stato !== Elezione::STATO_CANDIDATURE) {
            throw new ElezioneNonValidaException('Le candidature sono chiuse.');
        }

        $candidatura->update(['accettata_at' => $candidatura->accettata_at ?? now()]);
    }

    public function ritira(Candidatura $candidatura): void
    {
        if ($candidatura->elezione->stato !== Elezione::STATO_CANDIDATURE) {
            throw new ElezioneNonValidaException('Le candidature sono chiuse: non è più possibile ritirarsi.');
        }

        $candidatura->update(['ritirata_at' => now()]);
    }

    public function apriVoto(Elezione $elezione): void
    {
        if ($elezione->stato !== Elezione::STATO_CANDIDATURE) {
            throw new ElezioneNonValidaException('Il voto è già stato aperto.');
        }

        if (! Candidatura::query()->where('elezione_id', $elezione->id)->valide()->exists()) {
            throw new ElezioneNonValidaException('Non ci sono candidature valide.');
        }

        if ($this->assemblea->quorum($elezione->riunione)['costituita_in'] === null) {
            throw new ElezioneNonValidaException('L\'assemblea non ha raggiunto il quorum costitutivo: il voto non può essere aperto.');
        }

        $elezione->update(['stato' => Elezione::STATO_VOTO, 'voto_aperto_at' => now()]);
    }

    /**
     * Soci per cui il socio può votare (vedi VotoAssembleaService), esclusi
     * quelli che hanno già votato in questa elezione.
     *
     * @return Collection<string, string>
     */
    public function daVotare(Elezione $elezione, Socio $socio): Collection
    {
        $rappresentati = $this->voto->rappresentati($elezione->riunione, $socio);

        $votati = VotanteElezione::query()
            ->where('elezione_id', $elezione->id)
            ->whereIn('rappresentato_socio_id', $rappresentati->keys())
            ->pluck('rappresentato_socio_id');

        return $rappresentati->except($votati->all());
    }

    /**
     * Schede del socio: [id rappresentato => [id candidature]]; [] = bianca.
     *
     * @param  array<string, list<string>>  $schede
     */
    public function vota(Elezione $elezione, Socio $votante, array $schede): int
    {
        if (! $elezione->riunione->voto_online) {
            throw new ElezioneNonValidaException('In questa assemblea si vota con la scheda cartacea.');
        }

        $ammessi = $this->voto->rappresentati($elezione->riunione, $votante);

        if ($ammessi->isEmpty()) {
            throw new ElezioneNonValidaException('Per votare devi risultare presente all\'assemblea con diritto di voto.');
        }

        $candidature = Candidatura::query()->where('elezione_id', $elezione->id)->valide()->pluck('id')->all();

        foreach ($schede as $rappresentato => $preferenze) {
            $preferenze = array_values(array_unique($preferenze));

            if (! $ammessi->has($rappresentato)) {
                throw new ElezioneNonValidaException('Non puoi votare per uno dei soci indicati.');
            }

            if (count($preferenze) > $elezione->max_preferenze) {
                throw new ElezioneNonValidaException("Puoi esprimere al massimo {$elezione->max_preferenze} preferenze.");
            }

            if (array_diff($preferenze, $candidature) !== []) {
                throw new ElezioneNonValidaException('Una delle preferenze non corrisponde a una candidatura.');
            }

            $schede[$rappresentato] = $preferenze;
        }

        if ($schede === []) {
            throw new ElezioneNonValidaException('Compila la scheda.');
        }

        try {
            return DB::transaction(function () use ($elezione, $votante, $schede): int {
                $elezione = Elezione::query()->whereKey($elezione->id)->lockForUpdate()->firstOrFail();

                if ($elezione->stato !== Elezione::STATO_VOTO) {
                    throw new ElezioneNonValidaException('Il voto per questa elezione è chiuso.');
                }

                if (VotanteElezione::query()->where('elezione_id', $elezione->id)->whereIn('rappresentato_socio_id', array_keys($schede))->exists()) {
                    throw new ElezioneNonValidaException('La scheda è già stata votata.');
                }

                $rappresentati = array_keys($schede);
                shuffle($rappresentati);

                foreach ($rappresentati as $rappresentato) {
                    VotanteElezione::create([
                        'associazione_id' => $elezione->associazione_id,
                        'elezione_id' => $elezione->id,
                        'votante_socio_id' => $votante->id,
                        'rappresentato_socio_id' => $rappresentato,
                    ]);

                    SchedaElezione::create([
                        'associazione_id' => $elezione->associazione_id,
                        'elezione_id' => $elezione->id,
                        'preferenze' => $schede[$rappresentato],
                    ]);
                }

                return count($schede);
            });
        } catch (UniqueConstraintViolationException) {
            throw new ElezioneNonValidaException('La scheda è già stata votata.');
        }
    }

    /**
     * Chiude il voto e scrutina: preferenze online + preferenze delle schede
     * cartacee ([id candidatura => preferenze]).
     *
     * @param  array<string, int>  $preferenzeSala
     */
    public function scrutina(Elezione $elezione, array $preferenzeSala, int $valideSala, int $biancheSala, int $nulleSala): void
    {
        DB::transaction(function () use ($elezione, $preferenzeSala, $valideSala, $biancheSala, $nulleSala): void {
            $elezione = Elezione::query()->whereKey($elezione->id)->lockForUpdate()->firstOrFail();

            if ($elezione->stato !== Elezione::STATO_VOTO) {
                throw new ElezioneNonValidaException('Lo scrutinio si fa a voto aperto, chiudendolo.');
            }

            $candidature = Candidatura::query()
                ->where('elezione_id', $elezione->id)
                ->valide()
                ->with(['socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])
                ->get()
                ->keyBy('id');

            if (array_diff(array_keys($preferenzeSala), $candidature->keys()->all()) !== []) {
                throw new ElezioneNonValidaException('Le preferenze in sala indicano una candidatura non valida.');
            }

            $totaleSala = array_sum($preferenzeSala);
            if ($totaleSala > $valideSala * $elezione->max_preferenze) {
                throw new ElezioneNonValidaException("Le preferenze in sala ({$totaleSala}) sono più di quelle possibili con {$valideSala} schede valide.");
            }

            $schedeOnline = SchedaElezione::query()->where('elezione_id', $elezione->id)->get(['preferenze']);
            $schedeTotali = $schedeOnline->count() + $valideSala + $biancheSala + $nulleSala;
            $presenti = $this->assemblea->quorum($elezione->riunione)['presenti'];

            if ($schedeTotali > $presenti) {
                throw new ElezioneNonValidaException("Le schede ({$schedeTotali}) superano i presenti con diritto di voto ({$presenti}).");
            }

            $online = $schedeOnline->flatMap(fn (SchedaElezione $s) => $s->preferenze)->countBy();

            $classifica = $candidature
                ->map(fn (Candidatura $c) => [
                    'candidatura' => $c,
                    'totale' => (int) ($online[$c->id] ?? 0) + (int) ($preferenzeSala[$c->id] ?? 0),
                ])
                ->sort(fn (array $a, array $b) => $this->confronta($elezione, $a, $b))
                ->values();

            foreach ($classifica as $i => $riga) {
                $riga['candidatura']->update([
                    'preferenze_sala' => (int) ($preferenzeSala[$riga['candidatura']->id] ?? 0),
                    'preferenze_totali' => $riga['totale'],
                    'posizione' => $i + 1,
                    'eletta' => $i < $elezione->posti && $riga['totale'] > 0,
                ]);
            }

            $elezione->update([
                'stato' => Elezione::STATO_SCRUTINIO,
                'voto_chiuso_at' => now(),
                'schede_valide_sala' => $valideSala,
                'schede_bianche_sala' => $biancheSala,
                'schede_nulle_sala' => $nulleSala,
            ]);
        });
    }

    /**
     * Proclamazione: chiude le cariche uscenti il giorno prima dell'elezione
     * e crea quelle degli eletti dal giorno dell'elezione.
     *
     * @return int eletti
     */
    public function proclama(Elezione $elezione): int
    {
        if ($elezione->stato !== Elezione::STATO_SCRUTINIO) {
            throw new ElezioneNonValidaException('Si proclama dopo lo scrutinio.');
        }

        return DB::transaction(function () use ($elezione): int {
            $inizio = $elezione->riunione->data_ora->copy()->startOfDay();
            $fineUscenti = $inizio->copy()->subDay();

            Carica::query()
                ->withoutGlobalScope('sede')
                ->attive()
                ->whereIn('ruolo', $elezione->ruoliUscenti())
                ->get()
                ->each(fn (Carica $carica) => $carica->update([
                    'fine' => $carica->inizio->isAfter($fineUscenti) ? $carica->inizio : $fineUscenti,
                ]));

            $eletti = Candidatura::query()->where('elezione_id', $elezione->id)->where('eletta', true)->get();

            foreach ($eletti as $candidatura) {
                Carica::create([
                    'associazione_id' => $elezione->associazione_id,
                    'socio_id' => $candidatura->socio_id,
                    'ruolo' => $elezione->ruoloEletti(),
                    'inizio' => $inizio->toDateString(),
                    'fine' => $inizio->copy()->addMonthsNoOverflow($elezione->durata_mandato_mesi)->subDay()->toDateString(),
                    'note' => "Eletto/a nell'elezione del {$inizio->format('d/m/Y')} ({$elezione->organoLabel()}).",
                ]);
            }

            $elezione->update(['stato' => Elezione::STATO_PROCLAMATA, 'proclamata_at' => now()]);

            return $eletti->count();
        });
    }

    /**
     * Risultato dell'elezione per il verbale.
     */
    public function testoVerbale(Elezione $elezione): string
    {
        $candidature = Candidatura::query()
            ->where('elezione_id', $elezione->id)
            ->whereNotNull('posizione')
            ->with(['socio' => fn ($q) => $q->withoutGlobalScope('sede')->withTrashed()])
            ->orderBy('posizione')
            ->get();

        $schedeOnline = SchedaElezione::query()->where('elezione_id', $elezione->id)->get(['preferenze']);
        $biancheOnline = $schedeOnline->filter(fn (SchedaElezione $s) => $s->preferenze === [])->count();

        $righe = [sprintf(
            'Elezione %s (%d posti, massimo %d preferenze): schede %d (%d online, %d in sala), bianche %d, nulle %d.',
            $elezione->organoLabel(),
            $elezione->posti,
            $elezione->max_preferenze,
            $schedeOnline->count() + $elezione->schede_valide_sala + $elezione->schede_bianche_sala + $elezione->schede_nulle_sala,
            $schedeOnline->count(),
            $elezione->schede_valide_sala + $elezione->schede_bianche_sala + $elezione->schede_nulle_sala,
            $biancheOnline + $elezione->schede_bianche_sala,
            $elezione->schede_nulle_sala,
        )];

        foreach ($candidature as $c) {
            $righe[] = sprintf('   %d. %s: %d preferenze%s', $c->posizione, $c->socio?->nome_completo, $c->preferenze_totali, $c->eletta ? ' — eletto/a' : '');
        }

        return implode("\n", $righe);
    }

    private function verificaRequisiti(Elezione $elezione, Socio $socio): void
    {
        $riunione = $elezione->riunione;
        $data = $riunione->data_ora;

        if ($socio->stato !== Socio::STATO_ATTIVO) {
            throw new ElezioneNonValidaException("{$socio->nome_completo} non è un socio attivo.");
        }

        if ($socio->isMinorenne($data)) {
            throw new ElezioneNonValidaException("{$socio->nome_completo} sarà minorenne alla data dell'assemblea.");
        }

        if ($elezione->anzianita_minima_mesi > 0) {
            $limite = $data->copy()->subMonthsNoOverflow($elezione->anzianita_minima_mesi);

            if ($socio->data_iscrizione->isAfter($limite)) {
                throw new ElezioneNonValidaException("Per candidarsi servono almeno {$elezione->anzianita_minima_mesi} mesi di iscrizione.");
            }
        }

        if ($riunione->aventi_diritto_at !== null) {
            $avente = RiunionePartecipante::query()
                ->where('riunione_id', $riunione->id)
                ->where('socio_id', $socio->id)
                ->where('avente_diritto_voto', true)
                ->exists();

            if (! $avente) {
                throw new ElezioneNonValidaException("{$socio->nome_completo} non è tra gli aventi diritto al voto dell'assemblea.");
            }
        }
    }

    /**
     * Ordine della classifica: preferenze decrescenti, poi la regola di
     * parità (iscrizione o nascita più remota), poi cognome e nome.
     *
     * @param  array{candidatura: Candidatura, totale: int}  $a
     * @param  array{candidatura: Candidatura, totale: int}  $b
     */
    private function confronta(Elezione $elezione, array $a, array $b): int
    {
        if ($a['totale'] !== $b['totale']) {
            return $b['totale'] <=> $a['totale'];
        }

        $campo = $elezione->regola_parita === 'eta' ? 'data_nascita' : 'data_iscrizione';
        $da = $a['candidatura']->socio?->{$campo}?->toDateString() ?? '9999-12-31';
        $db = $b['candidatura']->socio?->{$campo}?->toDateString() ?? '9999-12-31';

        if ($da !== $db) {
            return $da <=> $db;
        }

        return [$a['candidatura']->socio?->cognome, $a['candidatura']->socio?->nome]
            <=> [$b['candidatura']->socio?->cognome, $b['candidatura']->socio?->nome];
    }
}
