<?php

namespace App\Listeners;

use App\Models\Utente;
use Illuminate\Auth\Events\Login;
use Spatie\Permission\Models\Role;

/**
 * Al login di un utente backend (guard web):
 * - aggiorna `last_login` (comportamento legacy);
 * - imposta in sessione il contesto tenant (`associazione_id`, `associazione_nome`);
 * - allinea il ruolo spatie/permission alla colonna legacy `utenti.ruolo`.
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

        // Sincronizza il ruolo spatie con la colonna legacy `ruolo` (fail-safe se i ruoli non sono seedati)
        if (in_array($utente->ruolo, Utente::RUOLI, true)
            && ! $utente->hasRole($utente->ruolo)
            && Role::query()->where('name', $utente->ruolo)->where('guard_name', 'web')->exists()
        ) {
            $utente->syncRoles([$utente->ruolo]);
        }
    }
}
