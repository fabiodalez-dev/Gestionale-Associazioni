<?php

namespace App\Services\Struttura;

use App\Models\Associazione;
use App\Models\Carica;
use App\Models\ConflittoRiunione;
use App\Models\DichiarazioneInteressi;
use App\Models\PromemoriaInviato;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Struttura\DichiarazioneInteressiNotification;
use App\Settings\PartecipazioneSettings;
use Illuminate\Support\Collection;

/**
 * Conflitti di interesse (issue #11).
 *
 * - Dichiarazione annuale: la presenta chi ha ricoperto una carica nell'anno,
 *   dall'area soci o tramite la segreteria; si può aggiornare durante l'anno.
 * - Raccolta: la segreteria la avvia per un anno; chi non ha ancora
 *   dichiarato riceve la richiesta subito e poi un promemoria al mese.
 * - In riunione: il conflitto dichiarato su un punto esclude il socio dal
 *   voto su quel punto (di persona e per delega) e dalla base di calcolo
 *   delle maggioranze, ma non dal quorum costitutivo.
 */
class ConflittiService
{
    /**
     * Soci con una carica attiva in qualche giorno dell'anno.
     *
     * @return Collection<int, Socio>
     */
    public function titolari(Associazione $associazione, int $anno): Collection
    {
        $inizioAnno = "{$anno}-01-01";
        $fineAnno = "{$anno}-12-31";

        // Query esplicite sull'associazione: il servizio gira anche dallo
        // scheduler, dove non c'è un tenant corrente
        $sociId = Carica::query()
            ->withoutGlobalScopes()
            ->where('associazione_id', $associazione->id)
            ->whereDate('inizio', '<=', $fineAnno)
            ->where(fn ($q) => $q->whereNull('fine')->orWhereDate('fine', '>=', $inizioAnno))
            ->pluck('socio_id')
            ->unique();

        // Tolti tenant e sede ma non SoftDeletes: i soci cancellati non
        // ricevono richieste né promemoria
        return Socio::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('associazione_id', $associazione->id)
            ->whereKey($sociId)
            ->with(['cariche' => fn ($q) => $q->withoutGlobalScopes()
                ->whereDate('inizio', '<=', $fineAnno)
                ->where(fn ($w) => $w->whereNull('fine')->orWhereDate('fine', '>=', $inizioAnno))])
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get();
    }

    public function dichiara(Socio $socio, int $anno, bool $nessunConflitto, ?string $interessi, ?Utente $registrataDa = null): DichiarazioneInteressi
    {
        $interessi = filled($interessi) ? trim((string) $interessi) : null;

        if (! $nessunConflitto && $interessi === null) {
            throw new ConflittoNonValidoException('Indica gli interessi da dichiarare oppure conferma di non averne.');
        }

        if (! $this->titolari($socio->associazione, $anno)->contains('id', $socio->id)) {
            throw new ConflittoNonValidoException("{$socio->nome_completo} non ha ricoperto cariche nel {$anno}.");
        }

        return DichiarazioneInteressi::updateOrCreate(
            ['socio_id' => $socio->id, 'anno' => $anno],
            [
                'associazione_id' => $socio->associazione_id,
                'nessun_conflitto' => $nessunConflitto,
                'interessi' => $nessunConflitto ? null : $interessi,
                'dichiarata_at' => now(),
                'registrata_da' => $registrataDa?->id,
            ],
        );
    }

    /**
     * Titolari che non hanno ancora dichiarato per l'anno.
     *
     * @return Collection<int, Socio>
     */
    public function mancanti(Associazione $associazione, int $anno): Collection
    {
        $dichiarati = DichiarazioneInteressi::query()
            ->withoutGlobalScopes()
            ->where('associazione_id', $associazione->id)
            ->where('anno', $anno)
            ->pluck('socio_id')
            ->flip();

        return $this->titolari($associazione, $anno)->reject(fn (Socio $s) => $dichiarati->has($s->id))->values();
    }

    /**
     * Avvia (o riprende) la raccolta dell'anno e invia subito la richiesta a
     * chi manca. Restituisce quanti soci sono stati avvisati.
     */
    public function avviaRaccolta(Associazione $associazione, int $anno): int
    {
        $associazione->impostazioni(PartecipazioneSettings::class)->aggiorna(['dichiarazioni_anno_campagna' => $anno]);

        return $this->invia($associazione, $anno);
    }

    public function fermaRaccolta(Associazione $associazione): void
    {
        $associazione->impostazioni(PartecipazioneSettings::class)->aggiorna(['dichiarazioni_anno_campagna' => null]);
    }

    /**
     * Promemoria mensile ai titolari che non hanno dichiarato (al massimo
     * uno al mese per socio). Restituisce quanti soci sono stati avvisati.
     */
    public function invia(Associazione $associazione, int $anno): int
    {
        $mese = (int) now()->format('Ym');
        $inviati = 0;

        foreach ($this->mancanti($associazione, $anno) as $socio) {
            if (blank($socio->email)) {
                continue;
            }

            $giaInviato = PromemoriaInviato::query()
                ->withoutGlobalScopes()
                ->where('socio_id', $socio->id)
                ->where('tipo', PromemoriaInviato::TIPO_DICHIARAZIONE_INTERESSI)
                ->where('step', $mese)
                ->exists();

            if ($giaInviato) {
                continue;
            }

            $socio->notify(new DichiarazioneInteressiNotification($associazione, $anno));

            PromemoriaInviato::create([
                'associazione_id' => $associazione->id,
                'socio_id' => $socio->id,
                'tipo' => PromemoriaInviato::TIPO_DICHIARAZIONE_INTERESSI,
                'step' => $mese,
                'inviato_at' => now(),
            ]);
            $inviati++;
        }

        return $inviati;
    }

    public function dichiaraConflitto(Riunione $riunione, int $puntoIndice, Socio $socio, ?string $motivazione): ConflittoRiunione
    {
        $odg = is_array($riunione->odg) ? $riunione->odg : [];

        if (! array_key_exists($puntoIndice, $odg)) {
            throw new ConflittoNonValidoException('Il punto indicato non esiste nell\'ordine del giorno.');
        }

        $partecipa = RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->where('socio_id', $socio->id)
            ->exists();

        if (! $partecipa) {
            throw new ConflittoNonValidoException("{$socio->nome_completo} non è tra i partecipanti della riunione.");
        }

        return ConflittoRiunione::updateOrCreate(
            ['riunione_id' => $riunione->id, 'punto_indice' => $puntoIndice, 'socio_id' => $socio->id],
            ['associazione_id' => $riunione->associazione_id, 'motivazione' => filled($motivazione) ? trim((string) $motivazione) : null],
        );
    }

    /**
     * Id dei soci in conflitto sul punto.
     *
     * @return list<string>
     */
    public function inConflitto(Riunione $riunione, int $puntoIndice): array
    {
        return ConflittoRiunione::query()
            ->where('riunione_id', $riunione->id)
            ->where('punto_indice', $puntoIndice)
            ->pluck('socio_id')
            ->all();
    }

    /**
     * Presenti con diritto di voto in conflitto sul punto: non votano e non
     * contano nella base delle maggioranze.
     */
    public function presentiInConflitto(Riunione $riunione, int $puntoIndice): int
    {
        $conflitti = $this->inConflitto($riunione, $puntoIndice);

        if ($conflitti === []) {
            return 0;
        }

        $diPersona = RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->whereIn('socio_id', $conflitti)
            ->where('avente_diritto_voto', true)
            ->where('stato', RiunionePartecipante::STATO_CONFERMATO)
            ->count();

        // Deleghe che sul punto non si possono esercitare: delegante in
        // conflitto, oppure delegato in conflitto, che non vota nemmeno per
        // conto d'altri (VotoAssembleaService::rappresentati)
        $perDelega = DelegheService::queryValide($riunione)
            ->where(fn ($q) => $q->whereIn('delegante_socio_id', $conflitti)->orWhereIn('delegato_socio_id', $conflitti))
            ->count();

        return $diPersona + $perDelega;
    }
}
