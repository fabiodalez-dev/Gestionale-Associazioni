<?php

namespace App\Forms;

use App\Models\Associazione;
use App\Support\PasswordPolicy;
use Metalogico\Formello\FormelloField;

/**
 * Registrazione di un account pubblico (frontend /a/{slug}/registrazione).
 * Regole: App\Http\Requests\FrontendPubblico\RegistrazioneAccountRequest.
 *
 * Contesto: 'associazione' (Associazione) per il link all'informativa privacy.
 */
class RegistrazioneAccountForm extends ModuloFormello
{
    protected function fields(): array
    {
        /** @var Associazione $associazione */
        $associazione = $this->contesto['associazione'];

        return [
            FormelloField::make('nome')->label('Nome')->columns(6)->required()
                ->attributes(['maxlength' => 100, 'autocomplete' => 'given-name']),
            FormelloField::make('cognome')->label('Cognome')->columns(6)->required()
                ->attributes(['maxlength' => 100, 'autocomplete' => 'family-name']),
            FormelloField::make('email')->label('Email')->type('email')->columns(6)->required()
                ->attributes(['maxlength' => 255, 'autocomplete' => 'email']),
            FormelloField::make('telefono')->label('Telefono')->type('tel')->columns(6)
                ->attributes(['maxlength' => 30, 'autocomplete' => 'tel']),
            FormelloField::make('password')->label('Password')->widget('password')->columns(6)->required()
                ->help(PasswordPolicy::hint())
                ->extra('campi_utente', ['nome', 'cognome', 'email']),
            FormelloField::make('password_confirmation')->label('Conferma password')->widget('password')->columns(6)->required()
                ->extra('misuratore', false),
            FormelloField::make('privacy')->label('Ho letto e accetto')->widget('consenso')->required()
                ->extra('link_url', route('privacy-policy.show', $associazione->id))
                ->extra('link_testo', 'l\'informativa sulla privacy'),
        ];
    }
}
