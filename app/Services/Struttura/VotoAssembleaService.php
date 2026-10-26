<?php

namespace App\Services\Struttura;

use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\VotanteRiunione;
use App\Models\VotazioneRiunione;
use App\Models\VotoRiunione;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Votazioni dei soci in assemblea (issue #8):
 * - la segreteria apre il voto su un punto (palese o segreto) quando
 *   l'assemblea è validamente costituita e lo chiude: al conteggio online
 *   si sommano gli eventuali voti espressi in sala, poi l'esito si calcola
 *   dalla maggioranza del punto;
 * - il socio presente vota dall'area soci per sé e per ciascun delegante la
 *   cui delega è valida (delegante non presente), anche con voti diversi;
 * - nel voto segreto il voto è salvato senza socio e senza data, separato dal
 *   registro di chi ha votato.
 *
 * Apertura, voto e chiusura bloccano la riga della votazione: nessun voto
 * entra dopo la chiusura e nessun socio vota due volte per lo stesso
 * rappresentato (anche grazie all'unique su votanti_riunione).
 */
class VotoAssembleaService
{
    public function __construct(
        private readonly AssembleaService $assemblea,
    ) {}

    public function apri(Riunione $riunione, int $puntoIndice, bool $segreta): VotazioneRiunione
    {
        if (! $riunione->isAssemblea() || ! $riunione->voto_online) {
            throw new VotoNonValidoException('Il voto online non è attivo per questa riunione.');
        }

        if ($riunione->stato !== Riunione::STATO_PIANIFICATA) {
            throw new VotoNonValidoException('Il voto online si apre durante lo svolgimento dell\'assemblea.');
        }

        $odg = is_array($riunione->odg) ? $riunione->odg : [];
        if (! array_key_exists($puntoIndice, $odg)) {
            throw new VotoNonValidoException('Il punto indicato non esiste nell\'ordine del giorno.');
        }

        if ($this->assemblea->quorum($riunione)['costituita_in'] === null) {
            throw new VotoNonValidoException('L\'assemblea non ha raggiunto il quorum costitutivo: il voto non può essere aperto.');
        }

        return DB::transaction(function () use ($riunione, $puntoIndice, $segreta): VotazioneRiunione {
            $esistente = VotazioneRiunione::query()
                ->where('riunione_id', $riunione->id)
                ->where('punto_indice', $puntoIndice)
                ->lockForUpdate()
                ->first();

            if ($esistente !== null) {
                throw new VotoNonValidoException($esistente->isAperta()
                    ? 'Il voto su questo punto è già aperto.'
                    : 'Su questo punto è già registrato un esito.');
            }

            if (VotazioneRiunione::query()->where('riunione_id', $riunione->id)->whereNotNull('aperta_at')->whereNull('chiusa_at')->exists()) {
                throw new VotoNonValidoException('C\'è già un voto aperto: chiudilo prima di aprirne un altro.');
            }

            // Esito provvisorio fino alla chiusura (la colonna non ammette null)
            return VotazioneRiunione::create([
                'associazione_id' => $riunione->associazione_id,
                'riunione_id' => $riunione->id,
                'punto_indice' => $puntoIndice,
                'esito' => VotazioneRiunione::ESITO_RINVIATO,
                'segreta' => $segreta,
                'aperta_at' => now(),
            ]);
        });
    }

    /**
     * Chiude il voto: totali = voti online + voti espressi in sala.
     *
     * @param  array{favorevoli?: int, contrari?: int, astenuti?: int}  $sala
     */
    public function chiudi(VotazioneRiunione $votazione, array $sala = []): VotazioneRiunione
    {
        $riunione = $votazione->riunione;

        return DB::transaction(function () use ($votazione, $riunione, $sala): VotazioneRiunione {
            $votazione = VotazioneRiunione::query()->whereKey($votazione->id)->lockForUpdate()->firstOrFail();

            if (! $votazione->isAperta()) {
                throw new VotoNonValidoException('Il voto su questo punto non è aperto.');
            }

            $online = VotoRiunione::query()
                ->where('votazione_id', $votazione->id)
                ->selectRaw('opzione, COUNT(*) AS totale')
                ->groupBy('opzione')
                ->pluck('totale', 'opzione');

            $favorevoli = (int) ($online[VotoRiunione::FAVOREVOLE] ?? 0) + (int) ($sala['favorevoli'] ?? 0);
            $contrari = (int) ($online[VotoRiunione::CONTRARIO] ?? 0) + (int) ($sala['contrari'] ?? 0);
            $astenuti = (int) ($online[VotoRiunione::ASTENUTO] ?? 0) + (int) ($sala['astenuti'] ?? 0);

            $presenti = $this->assemblea->quorum($riunione)['presenti'];
            $votanti = $favorevoli + $contrari + $astenuti;

            if ($votanti > $presenti) {
                throw new VotoNonValidoException("I voti ({$votanti}) superano i presenti con diritto di voto ({$presenti}): controlla i voti in sala.");
            }

            $votazione->update([
                'favorevoli' => $favorevoli,
                'contrari' => $contrari,
                'astenuti' => $astenuti,
                'esito' => $this->assemblea->esito(
                    $riunione->maggioranzaPunto($votazione->punto_indice),
                    $favorevoli,
                    $contrari,
                    $presenti,
                ),
                'chiusa_at' => now(),
            ]);

            return $votazione;
        });
    }

    /**
     * Soci per cui il socio può votare ora: sé stesso se presente con diritto
     * di voto, più i deleganti con delega valida. Chiave = id del
     * rappresentato, valore = nome da mostrare.
     *
     * @return Collection<string, string>
     */
    public function rappresentati(Riunione $riunione, Socio $socio): Collection
    {
        $presente = RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->where('socio_id', $socio->id)
            ->where('avente_diritto_voto', true)
            ->where('stato', RiunionePartecipante::STATO_CONFERMATO)
            ->exists();

        if (! $presente) {
            return collect();
        }

        $deleganti = DelegheService::queryValide($riunione)
            ->where('delegato_socio_id', $socio->id)
            ->with('delegante:id,nome,cognome')
            ->get()
            ->mapWithKeys(fn ($delega) => [$delega->delegante_socio_id => 'Per delega di '.$delega->delegante?->nome_completo]);

        return collect([$socio->id => 'Il tuo voto'])->union($deleganti);
    }

    /**
     * Registra i voti del socio: [id rappresentato => opzione]. I
     * rappresentati non ammessi o che hanno già votato sono rifiutati.
     *
     * @param  array<string, string>  $voti
     * @return int voti registrati
     */
    public function vota(VotazioneRiunione $votazione, Socio $votante, array $voti): int
    {
        $riunione = $votazione->riunione;
        $ammessi = $this->rappresentati($riunione, $votante);

        if ($ammessi->isEmpty()) {
            throw new VotoNonValidoException('Per votare devi risultare presente all\'assemblea con diritto di voto.');
        }

        foreach ($voti as $rappresentato => $opzione) {
            if (! $ammessi->has($rappresentato)) {
                throw new VotoNonValidoException('Non puoi votare per uno dei soci indicati.');
            }

            if (! array_key_exists($opzione, VotoRiunione::OPZIONI)) {
                throw new VotoNonValidoException('Scelta di voto non valida.');
            }
        }

        if ($voti === []) {
            throw new VotoNonValidoException('Indica il tuo voto.');
        }

        try {
            return DB::transaction(function () use ($votazione, $votante, $voti): int {
                $votazione = VotazioneRiunione::query()->whereKey($votazione->id)->lockForUpdate()->firstOrFail();

                if (! $votazione->isAperta()) {
                    throw new VotoNonValidoException('Il voto su questo punto è chiuso.');
                }

                $giaVotati = VotanteRiunione::query()
                    ->where('votazione_id', $votazione->id)
                    ->whereIn('rappresentato_socio_id', array_keys($voti))
                    ->exists();

                if ($giaVotati) {
                    throw new VotoNonValidoException('Il voto è già stato espresso.');
                }

                // Ordine casuale: anche la sequenza di inserimento non deve
                // ricalcare quella dei votanti
                $rappresentati = array_keys($voti);
                shuffle($rappresentati);

                foreach ($rappresentati as $rappresentato) {
                    $opzione = $voti[$rappresentato];

                    VotanteRiunione::create([
                        'associazione_id' => $votazione->associazione_id,
                        'votazione_id' => $votazione->id,
                        'votante_socio_id' => $votante->id,
                        'rappresentato_socio_id' => $rappresentato,
                    ]);

                    VotoRiunione::create([
                        'associazione_id' => $votazione->associazione_id,
                        'votazione_id' => $votazione->id,
                        'opzione' => $opzione,
                        'socio_id' => $votazione->segreta ? null : $rappresentato,
                    ]);
                }

                return count($voti);
            });
        } catch (UniqueConstraintViolationException) {
            throw new VotoNonValidoException('Il voto è già stato espresso.');
        }
    }

    /**
     * Presenza segnalata dal socio dall'area soci (assemblea a distanza):
     * dall'ora della prima convocazione finché l'assemblea è in corso.
     */
    public function partecipaDaRemoto(Riunione $riunione, Socio $socio): void
    {
        if (! $riunione->isAssemblea() || ! $riunione->partecipazione_remota) {
            throw new VotoNonValidoException('Questa assemblea non prevede la partecipazione a distanza.');
        }

        if ($riunione->stato !== Riunione::STATO_PIANIFICATA || $riunione->data_ora->isFuture()) {
            throw new VotoNonValidoException('Potrai segnalare la tua presenza dall\'ora della prima convocazione.');
        }

        $partecipante = RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->where('socio_id', $socio->id)
            ->first();

        if ($partecipante === null) {
            throw new VotoNonValidoException('Non risulti tra gli aventi diritto di questa assemblea.');
        }

        if (! $partecipante->isPresente()) {
            $partecipante->update([
                'stato' => RiunionePartecipante::STATO_CONFERMATO,
                'presente_at' => now(),
            ]);
        }
    }
}
