<?php

namespace App\Services\Struttura;

use App\Models\Carica;
use App\Models\DelegaRiunione;
use App\Models\Riunione;
use App\Models\RiunionePartecipante;
use App\Models\Socio;
use App\Models\Utente;
use App\Notifications\Struttura\DelegaAssembleaNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deleghe per l'assemblea dei soci (issue #7). Regole:
 * - delegante e delegato sono entrambi aventi diritto al voto;
 * - una sola delega per delegante;
 * - niente deleghe a catena: chi ha delegato non riceve deleghe e chi ne
 *   ha ricevute non delega;
 * - il delegato non supera il limite di deleghe della riunione;
 * - se la riunione lo prevede, chi ricopre una carica non riceve deleghe;
 * - si registrano e revocano prima dello svolgimento (dall'area soci solo
 *   fino all'ora della prima convocazione).
 *
 * Le verifiche di unicità e limite avvengono con la riga della riunione
 * bloccata, così due deleghe contemporanee non superano il limite.
 */
class DelegheService
{
    public function registra(
        Riunione $riunione,
        Socio $delegante,
        Socio $delegato,
        string $origine,
        ?Utente $registrataDa = null,
    ): DelegaRiunione {
        $this->verificaAperta($riunione, $origine);

        if ($delegante->id === $delegato->id) {
            throw new DelegaNonValidaException('Non è possibile delegare sé stessi.');
        }

        foreach ([$delegante, $delegato] as $socio) {
            if (! $this->haDirittoDiVoto($riunione, $socio)) {
                throw new DelegaNonValidaException("{$socio->nome_completo} non è tra gli aventi diritto al voto di questa assemblea.");
            }
        }

        if ($riunione->deleghe_vietate_cariche && Carica::query()->attive()->where('socio_id', $delegato->id)->exists()) {
            throw new DelegaNonValidaException("{$delegato->nome_completo} ricopre una carica sociale e non può ricevere deleghe.");
        }

        $delega = DB::transaction(function () use ($riunione, $delegante, $delegato, $origine, $registrataDa): DelegaRiunione {
            Riunione::query()->whereKey($riunione->id)->lockForUpdate()->first();

            $deleghe = DelegaRiunione::query()->where('riunione_id', $riunione->id);

            if ((clone $deleghe)->where('delegante_socio_id', $delegante->id)->exists()) {
                throw new DelegaNonValidaException("{$delegante->nome_completo} ha già una delega per questa assemblea: va revocata prima di farne un'altra.");
            }

            if ((clone $deleghe)->where('delegato_socio_id', $delegante->id)->exists()) {
                throw new DelegaNonValidaException("{$delegante->nome_completo} ha ricevuto deleghe e non può a sua volta delegare.");
            }

            if ((clone $deleghe)->where('delegante_socio_id', $delegato->id)->exists()) {
                throw new DelegaNonValidaException("{$delegato->nome_completo} ha delegato un altro socio e non può ricevere deleghe.");
            }

            $ricevute = (clone $deleghe)->where('delegato_socio_id', $delegato->id)->count();
            $limite = (int) $riunione->max_deleghe_per_socio;

            if ($ricevute >= $limite) {
                throw new DelegaNonValidaException(
                    "{$delegato->nome_completo} ha già raggiunto il numero massimo di deleghe ({$limite})."
                );
            }

            return DelegaRiunione::create([
                'associazione_id' => $riunione->associazione_id,
                'riunione_id' => $riunione->id,
                'delegante_socio_id' => $delegante->id,
                'delegato_socio_id' => $delegato->id,
                'origine' => $origine,
                'registrata_da' => $registrataDa?->id,
            ]);
        });

        $delega->setRelation('riunione', $riunione);
        $delega->setRelation('delegante', $delegante);
        $delega->setRelation('delegato', $delegato);

        foreach (['delegante' => $delegante, 'delegato' => $delegato] as $ruolo => $socio) {
            if (filled($socio->email)) {
                $socio->notify(new DelegaAssembleaNotification($delega, $ruolo));
            }
        }

        return $delega;
    }

    public function revoca(DelegaRiunione $delega, string $origine): void
    {
        $this->verificaAperta($delega->riunione, $origine);

        $delega->delete();
    }

    /**
     * Deleghe valide in assemblea: delegato presente, delegante assente.
     *
     * @return Builder<DelegaRiunione>
     */
    public static function queryValide(Riunione $riunione): Builder
    {
        $presente = fn (string $colonna, bool $presente) => function ($q) use ($riunione, $colonna, $presente) {
            $q->selectRaw('1')
                ->from('riunioni_partecipanti')
                ->where('riunioni_partecipanti.riunione_id', $riunione->id)
                ->whereColumn('riunioni_partecipanti.socio_id', "deleghe_riunione.{$colonna}")
                ->where('riunioni_partecipanti.avente_diritto_voto', true)
                ->where('riunioni_partecipanti.stato', $presente ? '=' : '!=', RiunionePartecipante::STATO_CONFERMATO);
        };

        return DelegaRiunione::query()
            ->where('deleghe_riunione.riunione_id', $riunione->id)
            ->whereExists($presente('delegato_socio_id', true))
            ->whereExists($presente('delegante_socio_id', false));
    }

    private function verificaAperta(Riunione $riunione, string $origine): void
    {
        if (! $riunione->ammetteDeleghe()) {
            throw new DelegaNonValidaException('Questa assemblea non ammette deleghe.');
        }

        if ($riunione->stato !== Riunione::STATO_PIANIFICATA) {
            throw new DelegaNonValidaException('Le deleghe si gestiscono solo prima dello svolgimento dell\'assemblea.');
        }

        if ($origine === DelegaRiunione::ORIGINE_AREA_SOCI && ! $riunione->data_ora->isFuture()) {
            throw new DelegaNonValidaException('L\'assemblea è già iniziata: per le deleghe rivolgiti alla segreteria.');
        }
    }

    private function haDirittoDiVoto(Riunione $riunione, Socio $socio): bool
    {
        return RiunionePartecipante::query()
            ->where('riunione_id', $riunione->id)
            ->where('socio_id', $socio->id)
            ->where('avente_diritto_voto', true)
            ->exists();
    }
}
