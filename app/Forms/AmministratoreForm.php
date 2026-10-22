<?php

namespace App\Forms;

use App\Support\PasswordPolicy;
use Metalogico\Formello\FormelloField;

/**
 * Creazione e modifica di un utente backend dal super_admin (pagina Utenti).
 * Regole: Store/UpdateAmministratoreRequest.
 *
 * Contesto: 'ruoli' (list<string>), 'associazioni' (array<string, string> id => nome).
 */
class AmministratoreForm extends ModuloFormello
{
    private const ETICHETTE_RUOLI = [
        'super_admin' => 'Super Admin',
        'admin_associazione' => 'Admin Associazione',
        'operatore' => 'Operatore',
    ];

    protected function fields(): array
    {
        $ruoli = [];
        foreach ((array) ($this->contesto['ruoli'] ?? []) as $ruolo) {
            $ruoli[$ruolo] = self::ETICHETTE_RUOLI[$ruolo] ?? $ruolo;
        }

        $campi = [
            FormelloField::make('nome')->label('Nome')->columns(6)->required()->attributes(['maxlength' => 100]),
            FormelloField::make('cognome')->label('Cognome')->columns(6)->attributes(['maxlength' => 100]),
            FormelloField::make('email')->label('Email')->type('email')->columns(6)->required()->attributes(['maxlength' => 255]),
            FormelloField::make('ruolo')->label('Ruolo')->widget('select')->columns(6)->required()->choices($ruoli),
            FormelloField::make('associazione_id')->label('Associazione')->widget('select')->columns(6)
                ->choices((array) ($this->contesto['associazioni'] ?? []))
                ->extra('placeholder', '-- Seleziona --')
                ->help('Obbligatoria per admin di associazione e operatori; i super admin non sono legati a un\'associazione.'),
            FormelloField::make('password')->label($this->isEditing() ? 'Password (vuota = invariata)' : 'Password')
                ->widget('password')->columns(6)
                ->help(PasswordPolicy::hint())
                ->extra('campi_utente', ['nome', 'cognome', 'email']),
        ];

        if ($this->isEditing()) {
            $campi[] = FormelloField::make('attivo')->label('Account attivo')->widget('consenso')
                ->extra('interruttore', true);
        } else {
            $campi[] = FormelloField::make('invia_invito')
                ->label('Invia invito via email (l\'utente imposterà la propria password — la password qui sopra diventa facoltativa)')
                ->widget('consenso')
                ->extra('interruttore', true)
                ->extra('invia_zero', false);
        }

        return $campi;
    }
}
