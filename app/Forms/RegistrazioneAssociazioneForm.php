<?php

namespace App\Forms;

use App\Models\RichiestaRegistrazione;
use App\Support\PasswordPolicy;
use Metalogico\Formello\FormelloField;

/**
 * Modulo pubblico con cui un'associazione chiede di iscriversi alla piattaforma.
 * Regole: RegistrazioneAssociazioneRequest.
 */
class RegistrazioneAssociazioneForm extends ModuloFormello
{
    public const GRUPPO_ASSOCIAZIONE = ['nome', 'tipo', 'email', 'codice_fiscale', 'partita_iva', 'telefono', 'citta', 'provincia', 'numero_soci_stimato', 'messaggio'];

    public const GRUPPO_ADMIN = ['admin_nome', 'admin_cognome', 'admin_email', 'admin_password'];

    protected function fields(): array
    {
        return [
            FormelloField::make('nome')->label('Nome dell\'associazione')->columns(8)->required()->attributes(['maxlength' => 255, 'autocomplete' => 'organization']),
            FormelloField::make('tipo')->label('Forma giuridica')->widget('select')->columns(4)->choices(['' => '— Seleziona —'] + RichiestaRegistrazione::TIPI),
            FormelloField::make('email')->label('Email dell\'associazione')->type('email')->columns(6)->required()->attributes(['maxlength' => 255]),
            FormelloField::make('telefono')->label('Telefono')->type('tel')->columns(6)->attributes(['maxlength' => 20]),
            FormelloField::make('codice_fiscale')->label('Codice fiscale')->columns(6)->attributes(['maxlength' => 16]),
            FormelloField::make('partita_iva')->label('Partita IVA')->columns(6)->attributes(['maxlength' => 20]),
            FormelloField::make('citta')->label('Città')->columns(6)->attributes(['maxlength' => 100]),
            FormelloField::make('provincia')->label('Provincia')->columns(2)->attributes(['maxlength' => 2, 'placeholder' => 'BO']),
            FormelloField::make('numero_soci_stimato')->label('Soci (circa)')->type('number')->columns(4)->attributes(['min' => 0, 'max' => 1000000]),
            FormelloField::make('messaggio')->label('Qualcosa da aggiungere?')->widget('textarea')->attributes(['rows' => 3, 'maxlength' => 2000, 'placeholder' => 'Facoltativo: attività, esigenze, da quale gestionale arrivate…']),

            FormelloField::make('admin_nome')->label('Nome')->columns(6)->required()->attributes(['maxlength' => 100, 'autocomplete' => 'given-name']),
            FormelloField::make('admin_cognome')->label('Cognome')->columns(6)->attributes(['maxlength' => 100, 'autocomplete' => 'family-name']),
            FormelloField::make('admin_email')->label('La tua email (per accedere)')->type('email')->columns(6)->required()->attributes(['maxlength' => 255, 'autocomplete' => 'email']),
            FormelloField::make('admin_password')->label('Password')->widget('password')->columns(6)->required()
                ->attributes(['autocomplete' => 'new-password'])
                ->help(PasswordPolicy::hint())
                ->extra('campi_utente', ['admin_nome', 'admin_cognome', 'admin_email', 'nome']),

            FormelloField::make('privacy')->label('Acconsento al trattamento dei dati inseriti per gestire la richiesta di iscrizione')
                ->widget('consenso')->required(),
        ];
    }
}
