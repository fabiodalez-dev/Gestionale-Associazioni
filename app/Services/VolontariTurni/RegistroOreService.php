<?php

namespace App\Services\VolontariTurni;

use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Registro ore volontariato (f.5 del piano "Organizzazione 3").
 *
 * Le ore sono derivate da turni_assegnazioni.ore_effettive (unica colonna
 * prevista dalle Fondamenta per il registro): vi finiscono sia le presenze
 * registrate a fine turno, sia gli inserimenti manuali "fuori turno", questi
 * ultimi modellati come un Turno chiuso dedicato (un solo socio) con la sua
 * assegnazione confermata. Questo evita nuove tabelle (i moduli non creano
 * migrations) mantenendo tutto interrogabile in un punto solo.
 */
class RegistroOreService
{
    /** Titolo dei turni-contenitore per le ore inserite manualmente. */
    public const PREFISSO_MANUALE = 'Ore manuali';

    /**
     * Registra le presenze a fine turno (ore effettive per i confermati).
     * Riapre il turno solo se richiesto; di norma lo chiude.
     *
     * @param  array<string, float|null>  $orePerAssegnazione  [assegnazione_id => ore]
     */
    public function registraPresenze(Turno $turno, array $orePerAssegnazione): int
    {
        $registrate = 0;

        $assegnazioni = $turno->assegnazioni()
            ->where('stato', TurnoAssegnazione::STATO_CONFERMATO)
            ->get();

        foreach ($assegnazioni as $assegnazione) {
            if (! array_key_exists($assegnazione->id, $orePerAssegnazione)) {
                continue;
            }

            $ore = $orePerAssegnazione[$assegnazione->id];

            $assegnazione->update([
                'ore_effettive' => $ore === null ? null : round((float) $ore, 2),
            ]);

            if ($ore !== null) {
                $registrate++;
            }
        }

        $turno->update(['stato' => Turno::STATO_CHIUSO]);

        return $registrate;
    }

    /**
     * Inserisce ore manuali "fuori turno" per un socio, creando il
     * turno-contenitore chiuso e l'assegnazione confermata con le ore.
     */
    public function aggiungiOreManuali(
        Socio $socio,
        float $ore,
        Carbon $data,
        ?string $descrizione = null
    ): TurnoAssegnazione {
        $titolo = self::PREFISSO_MANUALE.($descrizione !== null && $descrizione !== ''
            ? ' — '.mb_substr($descrizione, 0, 150)
            : '');

        $turno = Turno::create([
            'associazione_id' => $socio->associazione_id,
            'titolo' => $titolo,
            'data' => $data->toDateString(),
            'ora_inizio' => '00:00',
            'ora_fine' => '00:00',
            'posti' => 1,
            'note' => $descrizione,
            'stato' => Turno::STATO_CHIUSO,
            'auto_conferma' => false,
        ]);

        return TurnoAssegnazione::create([
            'associazione_id' => $socio->associazione_id,
            'turno_id' => $turno->id,
            'socio_id' => $socio->id,
            'stato' => TurnoAssegnazione::STATO_CONFERMATO,
            'ore_effettive' => round($ore, 2),
        ]);
    }

    /**
     * Verifica se un'assegnazione è un inserimento manuale "fuori turno".
     */
    public function isManuale(TurnoAssegnazione $assegnazione): bool
    {
        return str_starts_with((string) $assegnazione->turno?->titolo, self::PREFISSO_MANUALE);
    }

    /**
     * Totale ore registrate per un socio (scheda socio, f.5), opzionalmente
     * limitato a un anno solare.
     */
    public function totaleOreSocio(Socio $socio, ?int $anno = null): float
    {
        $query = TurnoAssegnazione::query()
            ->where('socio_id', $socio->id)
            ->whereNotNull('ore_effettive');

        if ($anno !== null) {
            $query->whereHas('turno', fn ($q) => $q->whereYear('data', $anno));
        }

        return round((float) $query->sum('ore_effettive'), 2);
    }

    /**
     * Voci del registro ore di un socio per un anno (attestato PDF, f.5),
     * ordinate per data del turno.
     *
     * @return Collection<int, TurnoAssegnazione>
     */
    public function vociAnno(Socio $socio, int $anno): Collection
    {
        return TurnoAssegnazione::query()
            ->with('turno.mansione')
            ->where('socio_id', $socio->id)
            ->whereNotNull('ore_effettive')
            ->whereHas('turno', fn ($q) => $q->whereYear('data', $anno))
            ->get()
            ->sortBy(fn (TurnoAssegnazione $a) => $a->turno?->data?->toDateString())
            ->values();
    }

    /**
     * Anni per cui esistono ore registrate per il socio (selezione attestato).
     *
     * @return list<int>
     */
    public function anniConOre(Socio $socio): array
    {
        return TurnoAssegnazione::query()
            ->where('socio_id', $socio->id)
            ->whereNotNull('ore_effettive')
            ->whereHas('turno')
            ->with('turno:id,data')
            ->get()
            ->map(fn (TurnoAssegnazione $a) => (int) $a->turno?->data?->format('Y'))
            ->filter()
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }
}
