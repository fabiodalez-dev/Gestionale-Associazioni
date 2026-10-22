<?php

namespace App\Listeners;

use App\Models\Utente;
use App\Support\RuoliAssociazione;
use Illuminate\Auth\Events\Login;
use Spatie\Permission\Models\Role;

/**
 * Al login di un utente backend (guard web):
 * - aggiorna `last_login` (comportamento legacy);
 * - imposta in sessione il contesto tenant (`associazione_id`, `associazione_nome`);
 * - allinea i ruoli spatie/permission alla colonna legacy `utenti.ruolo`, preservando le cariche.
 */
class AggiornaContestoLogin
{
    public function handle(Login $event): void
    {
        $utente = $event->user;

        if (! $utente instanceof Utente) {
            return;
        }

        $utente->forceFill(['last_login' => now()])->saveQuietly();

        // Contesto tenant in sessione (per super_admin resta vuoto: vista globale)
        session([
            'associazione_id' => $utente->associazione_id,
            'associazione_nome' => $utente->associazione_id
                ? $utente->associazione()->value('nome')
                : null,
        ]);

        $this->allineaRuoli($utente);
    }

    /**
     * Allinea i ruoli spatie alla colonna legacy `ruolo` senza cancellare le cariche
     * associative (presidente, tesoriere…) assegnate dalla pagina "Ruoli e permessi":
     * - super_admin / admin_associazione: il ruolo di sistema deve esserci;
     * - operatore: basta un qualsiasi ruolo staff; se non ne ha, riceve "operatore".
     * Fail-safe: nulla da fare se i ruoli non sono stati seedati.
     */
    private function allineaRuoli(Utente $utente): void
    {
        if (! in_array($utente->ruolo, Utente::RUOLI, true)
            || ! Role::query()->where('name', $utente->ruolo)->where('guard_name', 'web')->exists()) {
            return;
        }

        // Ruoli di sistema non più corrispondenti alla colonna (es. admin declassato a operatore)
        foreach ([Utente::RUOLO_SUPER_ADMIN, Utente::RUOLO_ADMIN_ASSOCIAZIONE] as $ruoloSistema) {
            if ($ruoloSistema !== $utente->ruolo && $utente->hasRole($ruoloSistema)) {
                $utente->removeRole($ruoloSistema);
            }
        }

        if ($utente->ruolo === Utente::RUOLO_OPERATORE) {
            if (! $utente->hasAnyRole(array_keys(RuoliAssociazione::RUOLI_STAFF))) {
                $utente->assignRole(Utente::RUOLO_OPERATORE);
            }

            return;
        }

        if (! $utente->hasRole($utente->ruolo)) {
            $utente->assignRole($utente->ruolo);
        }
    }
}
