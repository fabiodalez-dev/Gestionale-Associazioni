<?php

namespace App\Forms;

use App\Support\PasswordPolicy;
use Metalogico\Formello\FormelloField;

/**
 * Passo "amministratore" dell'installer: prima associazione e account super_admin.
 * Regole: App\Http\Requests\Installer\AdminRequest.
 */
class InstallerAdminForm extends ModuloFormello
{
    protected function fields(): array
    {
        return [
            FormelloField::make('associazione_nome')->label('Nome associazione')->columns(6)->required()
                ->attributes(['maxlength' => 255]),
            FormelloField::make('associazione_email')->label('Email associazione')->type('email')->columns(6)->required()
                ->attributes(['maxlength' => 255]),
            FormelloField::make('nome')->label('Nome')->columns(6)->required()
                ->attributes(['maxlength' => 100, 'autocomplete' => 'given-name']),
            FormelloField::make('cognome')->label('Cognome')->columns(6)->required()
                ->attributes(['maxlength' => 100, 'autocomplete' => 'family-name']),
            FormelloField::make('email')->label('Email di accesso')->type('email')->required()
                ->attributes(['maxlength' => 255, 'autocomplete' => 'username']),
            FormelloField::make('password')->label('Password')->widget('password')->columns(6)->required()
                ->help(PasswordPolicy::hint())
                ->extra('campi_utente', ['nome', 'cognome', 'email', 'associazione_email']),
            FormelloField::make('password_confirmation')->label('Conferma password')->widget('password')->columns(6)->required()
                ->extra('misuratore', false),
        ];
    }
}
