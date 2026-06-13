<?php

namespace App\Services\VolontariTurni;

use App\Models\Socio;
use App\Models\Turno;
use App\Models\TurnoAssegnazione;
use App\Models\Utente;
use App\Notifications\VolontariTurni\CandidaturaTurnoNotification;
use App\Notifications\VolontariTurni\RinunciaTurnoNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Auto-candidatura, conferma e rinuncia ai turni (f.4 del piano
 * "Organizzazione 3"). Centralizza le regole condivise tra area soci,
 * area account e admin: posti disponibili, auto_conferma, riapertura
 * dello slot e notifiche brandizzate agli amministratori del tenant.
 */
class CandidaturaService
{
    /**
     * Il socio si candida a un turno aperto e compatibile.
     * Con turno.auto_conferma → confermato subito; altrimenti proposto
     * e l'admin viene avvisato. Riusa una eventuale assegnazione
     * precedentemente rinunciata (unique turno+socio).
     *
     * @throws ValidationException se il turno è chiuso o senza posti
     */
    public function candidati(Turno $turno, Socio $socio): TurnoAssegnazione
    {
        if (! $turno->isAperto()) {
            throw ValidationException::withMessages([
                'turno' => 'Questo turno non accetta candidature.',
            ]);
        }

        $esistente = $turno->assegnazioni()->where('socio_id', $socio->id)->first();

        if ($esistente !== null && $esistente->stato !== TurnoAssegnazione::STATO_RINUNCIATO) {
            throw ValidationException::withMessages([
                'turno' => 'Risulti già candidato a questo turno.',
            ]);
        }

        if ($turno->postiDisponibili() < 1) {
            throw ValidationException::withMessages([
                'turno' => 'Non ci sono più posti disponibili per questo turno.',
            ]);
        }

        $stato = $turno->auto_conferma
            ? TurnoAssegnazione::STATO_CONFERMATO
            : TurnoAssegnazione::STATO_PROPOSTO;

        if ($esistente !== null) {
            $esistente->update([
                'stato' => $stato,
                'motivo_rinuncia' => null,
                'ore_effettive' => null,
            ]);
            $assegnazione = $esistente->fresh();
        } else {
            $assegnazione = TurnoAssegnazione::create([
                'associazione_id' => $turno->associazione_id,
                'turno_id' => $turno->id,
                'socio_id' => $socio->id,
                'stato' => $stato,
            ]);
        }

        if ($stato === TurnoAssegnazione::STATO_PROPOSTO) {
            $this->avvisaAdmin($assegnazione, new CandidaturaTurnoNotification($assegnazione));
        }

        return $assegnazione;
    }

    /**
     * Il socio (o l'admin per suo conto) rinuncia a un turno: lo stato
     * passa a "rinunciato" con motivo, lo slot torna disponibile e
     * l'admin viene avvisato.
     */
    public function rinuncia(TurnoAssegnazione $assegnazione, ?string $motivo = null): TurnoAssegnazione
    {
        $assegnazione->update([
            'stato' => TurnoAssegnazione::STATO_RINUNCIATO,
            'motivo_rinuncia' => $motivo !== null ? mb_substr($motivo, 0, 500) : null,
            'ore_effettive' => null,
        ]);

        $this->avvisaAdmin($assegnazione, new RinunciaTurnoNotification($assegnazione));

        return $assegnazione;
    }

    /**
     * Notifica agli amministratori attivi del tenant (admin_associazione +
     * super_admin del tenant) con email valida.
     */
    private function avvisaAdmin(TurnoAssegnazione $assegnazione, object $notification): void
    {
        $admins = Utente::query()
            ->where('associazione_id', $assegnazione->associazione_id)
            ->where('attivo', true)
            ->whereNotNull('email')
            ->get()
            ->filter(fn (Utente $u) => filled($u->email));

        if ($admins->isNotEmpty()) {
            Notification::send($admins, $notification);
        }
    }
}
