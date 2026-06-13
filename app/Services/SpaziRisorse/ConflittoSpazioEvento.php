<?php

namespace App\Services\SpaziRisorse;

use App\Models\Evento;
use App\Models\PrenotazioneSpazio;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Rilevazione conflitti per l'uso di uno spazio da parte di un evento
 * (f.11 piano "Organizzazione 3"): l'evento occupa lo spazio nella finestra
 * [data_evento, data_fine ?? data_evento] e va confrontato con:
 *   - le prenotazioni interne dello stesso spazio (f.8);
 *   - gli altri eventi assegnati allo stesso spazio.
 *
 * Il risultato è un elenco di descrizioni testuali dei conflitti: il
 * controller lo usa per mostrare un WARNING non bloccante (l'evento viene
 * comunque salvato). Tutto è scopato sul tenant dai global scope dei modelli.
 */
class ConflittoSpazioEvento
{
    /**
     * @return list<string> Descrizioni dei conflitti (vuoto se nessuno).
     */
    public function perEvento(Evento $evento): array
    {
        if ($evento->spazio_id === null) {
            return [];
        }

        $inizio = $evento->data_evento;
        $fine = $evento->data_fine ?? $evento->data_evento;

        // Finestra a durata zero: nessun overlap rilevabile in modo sensato
        if (! $fine->greaterThan($inizio)) {
            return $this->conflittiIstantanei($evento, $inizio);
        }

        $conflitti = [];

        foreach ($this->prenotazioniInOverlap($evento, $inizio, $fine) as $p) {
            $conflitti[] = sprintf(
                'Prenotazione "%s" dello stesso spazio (%s – %s)',
                $p->titolo,
                $p->dal->format('d/m/Y H:i'),
                $p->al->format('d/m/Y H:i'),
            );
        }

        foreach ($this->eventiInOverlap($evento, $inizio, $fine) as $e) {
            $conflitti[] = sprintf(
                'Evento "%s" nello stesso spazio (%s%s)',
                $e->titolo,
                $e->data_evento->format('d/m/Y H:i'),
                $e->data_fine ? ' – '.$e->data_fine->format('d/m/Y H:i') : '',
            );
        }

        return $conflitti;
    }

    /**
     * Prenotazioni dello spazio che si sovrappongono alla finestra evento.
     *
     * @return Collection<int, PrenotazioneSpazio>
     */
    private function prenotazioniInOverlap(Evento $evento, CarbonInterface $inizio, CarbonInterface $fine)
    {
        return PrenotazioneSpazio::query()
            ->where('spazio_id', $evento->spazio_id)
            ->where('dal', '<', $fine)
            ->where('al', '>', $inizio)
            ->orderBy('dal')
            ->get();
    }

    /**
     * Altri eventi assegnati allo stesso spazio in overlap (escluso sé stesso).
     *
     * @return Collection<int, Evento>
     */
    private function eventiInOverlap(Evento $evento, CarbonInterface $inizio, CarbonInterface $fine)
    {
        return Evento::query()
            ->where('spazio_id', $evento->spazio_id)
            ->whereKeyNot($evento->getKey())
            ->where('data_evento', '<', $fine)
            // Per gli eventi senza data_fine si usa data_evento come fine
            ->where(function ($q) use ($inizio) {
                $q->where('data_fine', '>', $inizio)
                    ->orWhere(function ($q2) use ($inizio) {
                        $q2->whereNull('data_fine')->where('data_evento', '>', $inizio);
                    });
            })
            ->orderBy('data_evento')
            ->get();
    }

    /**
     * Evento "istantaneo" (senza data_fine valida): conflitto solo se un'altra
     * occupazione comprende l'istante di inizio.
     *
     * @return list<string>
     */
    private function conflittiIstantanei(Evento $evento, CarbonInterface $istante): array
    {
        $conflitti = [];

        $prenotazioni = PrenotazioneSpazio::query()
            ->where('spazio_id', $evento->spazio_id)
            ->where('dal', '<=', $istante)
            ->where('al', '>', $istante)
            ->get();

        foreach ($prenotazioni as $p) {
            $conflitti[] = sprintf(
                'Prenotazione "%s" dello stesso spazio (%s – %s)',
                $p->titolo,
                $p->dal->format('d/m/Y H:i'),
                $p->al->format('d/m/Y H:i'),
            );
        }

        return $conflitti;
    }
}
