<?php

namespace App\Services\VolontariTurni;

use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Helper transazionale condiviso per il consumo dei posti di un turno.
 *
 * Concorrenza (stessa regola di PrenotazioneService): ogni operazione che
 * occupa un posto gira in DB::transaction acquisendo PRIMA lockForUpdate()
 * sulla riga `turni` (mutex per-turno); il conteggio dei posti disponibili
 * viene SEMPRE rifatto DENTRO il lock prima di creare/confermare
 * l'assegnazione. Senza questo, due richieste concorrenti sull'ultimo posto
 * leggono entrambe postiDisponibili()==1, superano il check e sovra-riempiono
 * il turno (l'unique turno+socio non protegge perché i soci sono distinti).
 *
 * Centralizza i percorsi self-service (CandidaturaService::candidati) e admin
 * (TurnoController::assegna/conferma) sullo stesso mutex di turno, così tutti
 * si serializzano.
 */
class PostiTurnoService
{
    /**
     * Occupa un posto del turno per un socio, dentro il mutex per-turno.
     *
     * Ricontrolla isAperto/posti disponibili e l'eventuale assegnazione
     * esistente DENTRO il lock. Riusa una assegnazione precedentemente
     * rinunciata (unique turno+socio); blocca se il socio è già candidato.
     *
     * @param  bool  $richiediApertura  se true, esige turno aperto (auto-candidatura)
     * @param  bool  $aggiornaSeAttivo  se true (percorso admin), aggiorna in
     *                                  loco un'assegnazione già attiva
     *                                  (cambio stato, nessun nuovo posto);
     *                                  se false (self-service) blocca con
     *                                  "già candidato"
     *
     * @throws ValidationException se il turno è chiuso o senza posti
     */
    public function occupa(
        Turno $turno,
        string $socioId,
        string $statoTarget,
        bool $richiediApertura,
        bool $aggiornaSeAttivo = false,
    ): TurnoAssegnazione {
        return DB::transaction(function () use ($turno, $socioId, $statoTarget, $richiediApertura, $aggiornaSeAttivo): TurnoAssegnazione {
            $turno = $this->lockTurno($turno->id);

            if ($richiediApertura && ! $turno->isAperto()) {
                throw ValidationException::withMessages([
                    'turno' => 'Questo turno non accetta candidature.',
                ]);
            }

            $esistente = $turno->assegnazioni()
                ->where('socio_id', $socioId)
                ->lockForUpdate()
                ->first();

            $attivo = $esistente !== null
                && $esistente->stato !== TurnoAssegnazione::STATO_RINUNCIATO;

            if ($attivo && ! $aggiornaSeAttivo) {
                throw ValidationException::withMessages([
                    'turno' => 'Risulti già candidato a questo turno.',
                ]);
            }

            // Aggiornare un'assegnazione GIÀ attiva (admin che cambia stato)
            // non consuma un nuovo posto: lo è già. Solo l'occupazione di un
            // NUOVO posto (create, o riattivazione di un rinunciato) ricontrolla
            // la disponibilità DENTRO il lock, immune al TOCTOU del check pre-lock.
            if (! $attivo && $this->postiDisponibiliLocked($turno) < 1) {
                throw ValidationException::withMessages([
                    'turno' => 'Non ci sono più posti disponibili per questo turno.',
                ]);
            }

            if ($esistente !== null) {
                $esistente->update([
                    'stato' => $statoTarget,
                    'motivo_rinuncia' => null,
                    'ore_effettive' => null,
                ]);

                return $esistente->fresh();
            }

            return TurnoAssegnazione::create([
                'associazione_id' => $turno->associazione_id,
                'turno_id' => $turno->id,
                'socio_id' => $socioId,
                'stato' => $statoTarget,
            ]);
        });
    }

    /**
     * Conferma un'assegnazione proposta dentro il mutex per-turno.
     *
     * Se l'assegnazione è già confermata è idempotente (nessun posto in più
     * consumato). Altrimenti ricontrolla i posti DENTRO il lock prima di
     * promuovere proposto → confermato.
     *
     * @throws ValidationException se non ci sono posti per confermare
     */
    public function conferma(Turno $turno, TurnoAssegnazione $assegnazione): TurnoAssegnazione
    {
        return DB::transaction(function () use ($turno, $assegnazione): TurnoAssegnazione {
            $this->lockTurno($turno->id);

            $assegnazione = TurnoAssegnazione::query()
                ->whereKey($assegnazione->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Già confermata → idempotente, nessun posto in più consumato.
            // Una proposta occupa GIÀ il suo posto (postiDisponibiliLocked
            // conta proposti e confermati): promuoverla non ne consuma uno
            // nuovo, altrimenti l'ultimo posto di un turno pieno non sarebbe
            // mai confermabile. Solo la riattivazione di una rinunciata
            // ricontrolla i posti DENTRO il lock, serializzata sul mutex.
            $occupaGiaUnPosto = in_array($assegnazione->stato, [
                TurnoAssegnazione::STATO_PROPOSTO,
                TurnoAssegnazione::STATO_CONFERMATO,
            ], true);

            if (! $occupaGiaUnPosto && $this->postiDisponibiliLocked($turno) < 1) {
                throw ValidationException::withMessages([
                    'turno' => 'Non ci sono posti disponibili per confermare.',
                ]);
            }

            $assegnazione->update(['stato' => TurnoAssegnazione::STATO_CONFERMATO]);

            return $assegnazione->fresh();
        });
    }

    /**
     * Mutex per-turno: SELECT ... FOR UPDATE sulla riga turni.
     * Va chiamato come PRIMA acquisizione dentro la transazione.
     */
    private function lockTurno(string $turnoId): Turno
    {
        return Turno::query()
            ->whereKey($turnoId)
            ->lockForUpdate()
            ->firstOrFail();
    }

    /**
     * Posti disponibili DENTRO il lock: ricontati sulle assegnazioni attive
     * (proposto/confermato), senza fidarsi del modello passato dal chiamante.
     */
    private function postiDisponibiliLocked(Turno $turno): int
    {
        $occupati = $turno->assegnazioni()
            ->whereIn('stato', [
                TurnoAssegnazione::STATO_PROPOSTO,
                TurnoAssegnazione::STATO_CONFERMATO,
            ])
            ->count();

        return max(0, (int) $turno->posti - $occupati);
    }
}
