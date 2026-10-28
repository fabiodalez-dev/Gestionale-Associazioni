<?php

namespace App\Services\Comunicazione;

use App\Models\Consenso;
use App\Models\RubricaContatto;
use App\Models\RubricaScheda;
use App\Models\Socio;
use App\Notifications\Comunicazione\ContattoRubricaNotification;
use App\Settings\PartecipazioneSettings;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rubrica interna dei soci (issue #14).
 *
 * - Nessuno è in rubrica per default: il socio entra con un consenso
 *   esplicito (registrato in `consensi`) e sceglie i campi da mostrare;
 *   la revoca lo toglie subito.
 * - Consulta la rubrica il socio attivo maggiorenne e, con la reciprocità,
 *   solo se vi compare a sua volta. Non compaiono mai minorenni, soci non
 *   attivi o cancellati. I filtri usano solo i campi resi visibili.
 * - Il contatto passa dalla piattaforma: il destinatario riceve l'email con
 *   "rispondi a" del mittente, senza che il mittente veda il suo indirizzo;
 *   massimo 5 contatti al giorno e uno al giorno per destinatario.
 */
class RubricaService
{
    public const CONSENSO = 'rubrica_interna';

    public const TESTO_CONSENSO = 'Acconsento a comparire nella rubrica interna dell\'associazione, visibile agli altri soci, con i dati che ho scelto di mostrare.';

    private const CONTATTI_AL_GIORNO = 5;

    public function impostazioni(Socio $socio): PartecipazioneSettings
    {
        return $socio->associazione->impostazioni(PartecipazioneSettings::class);
    }

    public function scheda(Socio $socio): RubricaScheda
    {
        return RubricaScheda::query()->firstOrNew(
            ['socio_id' => $socio->id],
            ['associazione_id' => $socio->associazione_id],
        );
    }

    /**
     * Perché il socio non può usare la rubrica, oppure null se può entrarci.
     */
    public function motivoEsclusione(Socio $socio): ?string
    {
        return match (true) {
            ! $this->impostazioni($socio)->rubrica_attiva => 'La rubrica non è attiva in questa associazione.',
            ! $socio->isAttivo() => 'La rubrica è riservata ai soci attivi.',
            $socio->isMinorenne() => 'La rubrica è riservata ai soci maggiorenni.',
            default => null,
        };
    }

    /**
     * Entra in rubrica (o aggiorna la scheda): al primo ingresso, o dopo
     * una revoca, registra il consenso.
     *
     * @param  list<string>  $campi
     * @param  list<string>  $interessi
     */
    public function salva(Socio $socio, array $campi, ?string $presentazione, array $interessi, ?string $ip = null): RubricaScheda
    {
        if (($motivo = $this->motivoEsclusione($socio)) !== null) {
            throw new RubricaNonValidaException($motivo);
        }

        $ammessi = $this->impostazioni($socio)->rubrica_campi;
        $scheda = $this->scheda($socio);
        $nuovoConsenso = ! $scheda->attiva;

        DB::transaction(function () use ($socio, $scheda, $campi, $ammessi, $presentazione, $interessi, $nuovoConsenso, $ip): void {
            $scheda->fill([
                'attiva' => true,
                'campi_visibili' => array_values(array_intersect($ammessi, $campi)),
                'presentazione' => filled($presentazione) ? mb_substr(trim((string) $presentazione), 0, 1000) : null,
                // array_slice rinumera le chiavi lasciate da array_filter e array_unique
                'interessi' => array_slice(array_unique(array_filter(array_map(fn ($i) => mb_substr(trim((string) $i), 0, 50), $interessi))), 0, 20),
                'consenso_at' => $nuovoConsenso ? now() : $scheda->consenso_at,
                'revocata_at' => null,
            ])->save();

            if ($nuovoConsenso) {
                $this->registraConsenso($socio, true, $ip);
            }
        });

        return $scheda;
    }

    public function revoca(Socio $socio, ?string $ip = null): void
    {
        $scheda = $this->scheda($socio);

        if (! $scheda->exists || ! $scheda->attiva) {
            return;
        }

        DB::transaction(function () use ($socio, $scheda, $ip): void {
            $scheda->update(['attiva' => false, 'revocata_at' => now()]);
            $this->registraConsenso($socio, false, $ip);
        });
    }

    /**
     * Perché il socio non può consultare la rubrica, oppure null se può.
     */
    public function motivoBloccoConsultazione(Socio $socio): ?string
    {
        if (($motivo = $this->motivoEsclusione($socio)) !== null) {
            return $motivo;
        }

        if ($this->impostazioni($socio)->rubrica_reciprocita && ! $this->scheda($socio)->attiva) {
            return 'Per consultare la rubrica devi comparirci anche tu.';
        }

        return null;
    }

    /**
     * Schede visibili, con i filtri limitati ai campi resi pubblici e
     * ammessi oggi dall'associazione: un campo tolto dall'admin sparisce
     * anche dalle schede già salvate, senza che i soci debbano risalvarle.
     *
     * @param  array{nome?: ?string, citta?: ?string, interesse?: ?string}  $filtri
     * @param  list<string>  $ammessi  campi consentiti (PartecipazioneSettings::rubrica_campi)
     * @return LengthAwarePaginator<int, RubricaScheda>
     */
    public function cerca(array $filtri, array $ammessi): LengthAwarePaginator
    {
        $like = fn (?string $v) => '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $v)).'%';
        $citta = filled($filtri['citta'] ?? null);
        $interesse = filled($filtri['interesse'] ?? null);

        return $this->visibili()
            ->when(filled($filtri['nome'] ?? null), fn ($q) => $q->whereHas('socio', fn ($s) => $s->withoutGlobalScope('sede')
                ->where(fn ($w) => $w->where('nome', 'like', $like($filtri['nome']))->orWhere('cognome', 'like', $like($filtri['nome'])))))
            ->when($citta && ! in_array('citta', $ammessi, true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($citta, fn ($q) => $q
                ->whereJsonContains('campi_visibili', 'citta')
                ->whereHas('socio', fn ($s) => $s->withoutGlobalScope('sede')->where('citta', 'like', $like($filtri['citta']))))
            ->when($interesse && ! in_array('interessi', $ammessi, true), fn ($q) => $q->whereRaw('1 = 0'))
            ->when($interesse, fn ($q) => $q->whereKey($this->conInteresse((string) $filtri['interesse'])))
            ->with(['socio' => fn ($s) => $s->withoutGlobalScope('sede')->with('sede:id,nome')])
            ->join('soci', 'soci.id', '=', 'rubrica_schede.socio_id')
            ->orderBy('soci.cognome')
            ->orderBy('soci.nome')
            ->select('rubrica_schede.*')
            ->paginate(20)
            ->withQueryString()
            ->through(function (RubricaScheda $scheda) use ($ammessi): RubricaScheda {
                // Solo in memoria: la scelta del socio resta salvata com'è
                $scheda->campi_visibili = array_values(array_intersect((array) $scheda->campi_visibili, $ammessi));

                return $scheda;
            });
    }

    public function contatta(Socio $mittente, RubricaScheda $scheda, string $oggetto, string $testo): void
    {
        if (($motivo = $this->motivoBloccoConsultazione($mittente)) !== null) {
            throw new RubricaNonValidaException($motivo);
        }

        if (! $this->visibili()->whereKey($scheda->id)->exists()) {
            throw new RubricaNonValidaException('Il socio non è più in rubrica.');
        }

        if ($scheda->socio_id === $mittente->id) {
            throw new RubricaNonValidaException('Non puoi scrivere a te stesso.');
        }

        if (blank($mittente->email)) {
            throw new RubricaNonValidaException('Per scrivere serve un tuo indirizzo email: aggiungilo nel profilo.');
        }

        $ultimoGiorno = RubricaContatto::query()->where('mittente_socio_id', $mittente->id)->where('created_at', '>=', now()->subDay());

        if ((clone $ultimoGiorno)->count() >= self::CONTATTI_AL_GIORNO) {
            throw new RubricaNonValidaException('Hai raggiunto il numero massimo di messaggi per oggi: riprova domani.');
        }

        if ((clone $ultimoGiorno)->where('destinatario_socio_id', $scheda->socio_id)->exists()) {
            throw new RubricaNonValidaException('Hai già scritto a questo socio nelle ultime 24 ore.');
        }

        $contatto = RubricaContatto::create([
            'associazione_id' => $mittente->associazione_id,
            'mittente_socio_id' => $mittente->id,
            'destinatario_socio_id' => $scheda->socio_id,
            'oggetto' => mb_substr(trim($oggetto), 0, 150),
        ]);

        $scheda->socio->notify(new ContattoRubricaNotification($contatto, $mittente, trim($testo)));
    }

    public function segnala(RubricaContatto $contatto, Socio $destinatario): void
    {
        if ($contatto->destinatario_socio_id !== $destinatario->id) {
            throw new RubricaNonValidaException('Puoi segnalare solo i messaggi ricevuti.');
        }

        $contatto->update(['segnalato_at' => $contatto->segnalato_at ?? now()]);
    }

    /**
     * Id delle schede con un interesse che contiene il testo cercato. Il
     * confronto avviene in PHP perché la colonna JSON non si confronta allo
     * stesso modo sui database supportati (collation binaria su MySQL,
     * caratteri accentati scritti come \u00e8 su MariaDB e SQLite); le
     * schede in rubrica sono poche, al più quanto i soci.
     *
     * @return list<string>
     */
    private function conInteresse(string $testo): array
    {
        $cercato = mb_strtolower(trim($testo));

        return $this->visibili()
            ->whereJsonContains('campi_visibili', 'interessi')
            ->get(['rubrica_schede.id', 'rubrica_schede.interessi'])
            ->filter(fn (RubricaScheda $scheda) => collect((array) $scheda->interessi)
                ->contains(fn ($voce) => str_contains(mb_strtolower((string) $voce), $cercato)))
            ->pluck('id')
            ->all();
    }

    /**
     * Schede attive di soci attivi, maggiorenni e non cancellati.
     *
     * @return Builder<RubricaScheda>
     */
    private function visibili(): Builder
    {
        return RubricaScheda::query()
            ->where('rubrica_schede.attiva', true)
            ->whereHas('socio', fn ($s) => $s->withoutGlobalScope('sede')
                ->where('stato', Socio::STATO_ATTIVO)
                ->where(fn ($m) => $m->whereNull('data_nascita')->orWhereDate('data_nascita', '<=', now()->subYears(18)->toDateString())));
    }

    private function registraConsenso(Socio $socio, bool $accettato, ?string $ip): void
    {
        Consenso::create([
            'associazione_id' => $socio->associazione_id,
            'socio_id' => $socio->id,
            'chiave' => self::CONSENSO,
            'testo' => self::TESTO_CONSENSO,
            'accettato' => $accettato,
            'origine' => 'area_soci',
            'ip' => $ip,
        ]);
    }
}
