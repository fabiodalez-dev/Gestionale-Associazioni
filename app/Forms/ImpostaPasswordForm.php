<?php

namespace App\Forms;

use App\Support\PasswordPolicy;
use Metalogico\Formello\FormelloField;

/**
 * Impostazione o cambio password (invito admin, reset admin/account, primo accesso
 * socio, cambio password dal profilo). Le regole restano nelle rispettive request,
 * tutte basate su App\Support\PasswordPolicy.
 *
 * Contesto:
 * - 'dati_utente' (list<string>): nome, cognome, email dell'utente, per l'indicatore;
 * - 'campi_utente' (list<string>): id di campi della pagina con dati dell'utente (es. 'email');
 * - 'password_attuale' (string|null): nome del campo password attuale, se richiesto
 *   (es. 'current_password', 'password_corrente');
 * - 'etichetta' (string): etichetta del campo nuova password;
 * - 'colonne' (int): larghezza dei due campi nuova password/conferma (default 12).
 */
class ImpostaPasswordForm extends ModuloFormello
{
    protected function fields(): array
    {
        $campi = [];
        $colonne = (int) ($this->contesto['colonne'] ?? 12);

        if (filled($this->contesto['password_attuale'] ?? null)) {
            $campi[] = FormelloField::make((string) $this->contesto['password_attuale'])
                ->label('Password attuale')->widget('password')->required()
                ->extra('misuratore', false)
                ->extra('autocomplete', 'current-password');
        }

        $campi[] = FormelloField::make('password')
            ->label((string) ($this->contesto['etichetta'] ?? 'Nuova password'))
            ->widget('password')->columns($colonne)->required()
            ->help(PasswordPolicy::hint())
            ->extra('dati_utente', array_values(array_filter((array) ($this->contesto['dati_utente'] ?? []))))
            ->extra('campi_utente', (array) ($this->contesto['campi_utente'] ?? []));

        $campi[] = FormelloField::make('password_confirmation')
            ->label('Conferma password')->widget('password')->columns($colonne)->required()
            ->extra('misuratore', false);

        return $campi;
    }
}
