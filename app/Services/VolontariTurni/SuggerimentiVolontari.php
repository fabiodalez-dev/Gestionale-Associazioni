<?php

namespace App\Services\VolontariTurni;

use App\Models\Socio;
use App\Models\Turno;
use Illuminate\Support\Collection;

/**
 * Suggerimento volontari per un turno (f.3 del piano "Organizzazione 3"):
 * soci volontari attivi, ordinati per pertinenza — chi possiede la mansione
 * richiesta e chi è disponibile nella fascia del turno compaiono per primi.
 */
class SuggerimentiVolontari
{
    /**
     * @return Collection<int, array{socio: Socio, ha_mansione: bool, disponibile: bool, score: int}>
     */
    public function perTurno(Turno $turno, int $limite = 30): Collection
    {
        $giorno = Disponibilita::giornoDaIso((int) $turno->data->isoWeekday());
        $fascia = Disponibilita::fasciaDaOra((string) $turno->ora_inizio);

        // Esclude chi è già assegnato (proposto/confermato) sullo stesso turno.
        $giaAssegnati = $turno->assegnazioni()
            ->whereIn('stato', ['proposto', 'confermato'])
            ->pluck('socio_id')
            ->all();

        $soci = Socio::query()
            ->where('volontario_attivo', true)
            ->where('stato', Socio::STATO_ATTIVO)
            ->whereNotIn('id', $giaAssegnati)
            ->with('mansioni:id')
            ->orderBy('cognome')
            ->orderBy('nome')
            ->get();

        return $soci
            ->map(function (Socio $socio) use ($turno, $giorno, $fascia) {
                $haMansione = $turno->mansione_id !== null
                    && $socio->mansioni->contains('id', $turno->mansione_id);

                $disponibile = Disponibilita::copre($socio->disponibilita, $giorno, $fascia);

                return [
                    'socio' => $socio,
                    'ha_mansione' => $haMansione,
                    'disponibile' => $disponibile,
                    'score' => $this->score($haMansione, $disponibile),
                ];
            })
            ->sortByDesc('score')
            ->values()
            ->take($limite);
    }

    /**
     * Punteggio di pertinenza: mansione (peso 2) + disponibilità (peso 1).
     */
    private function score(bool $haMansione, bool $disponibile): int
    {
        $valore = 0;

        if ($haMansione) {
            $valore += 2;
        }

        if ($disponibile) {
            $valore += 1;
        }

        return $valore;
    }
}
