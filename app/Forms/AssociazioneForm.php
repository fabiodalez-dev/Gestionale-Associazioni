<?php

namespace App\Forms;

use App\Support\PasswordPolicy;
use Metalogico\Formello\FormelloField;

/**
 * Creazione e modifica di un'associazione dal super_admin, compreso il nuovo admin.
 * Regole: Store/UpdateAssociazioneRequest. Logo, collegamento di un admin esistente,
 * sede e clonazione restano nella pagina perché hanno logica JS dedicata.
 *
 * Gruppi di campi per la pagina: GRUPPO_ANAGRAFICA, GRUPPO_CONFIGURAZIONE, GRUPPO_ADMIN.
 */
class AssociazioneForm extends ModuloFormello
{
    public const GRUPPO_ANAGRAFICA = ['nome', 'email', 'slug', 'partita_iva', 'codice_fiscale', 'telefono', 'indirizzo', 'citta', 'provincia', 'cap'];

    public const GRUPPO_CONFIGURAZIONE = ['tipo_scadenza_default', 'giorni_notifica_scadenza', 'costo_tessera', 'attiva'];

    public const GRUPPO_ADMIN = ['admin_nome', 'admin_cognome', 'admin_email', 'admin_password'];

    protected function fields(): array
    {
        $nuova = $this->isCreating();
        $slug = $this->model->getAttribute('slug');

        $giorni = FormelloField::make('giorni_notifica_scadenza')->label('Giorni preavviso notifica')->type('number')->columns(4)
            ->attributes(['min' => 1, 'max' => 365]);
        $tipoScadenza = FormelloField::make('tipo_scadenza_default')->label('Tipo scadenza tessere')->widget('select')->columns(4)
            ->choices(['solare' => 'Anno Solare (31 Dicembre)', 'annuale' => 'Annuale (365 giorni da iscrizione)']);
        $attiva = FormelloField::make('attiva')->label('Associazione attiva')->widget('consenso')->columns(4)
            ->extra('interruttore', true);
        if ($nuova) {
            $giorni->value(30);
            $tipoScadenza->value('solare');
            $attiva->value(true);
        }

        return [
            FormelloField::make('nome')->label('Nome')->columns(6)->required()->attributes(['maxlength' => 255]),
            FormelloField::make('email')->label('Email')->type('email')->columns(6)->required()->attributes(['maxlength' => 255]),
            FormelloField::make('slug')->label('Slug mini-sito pubblico')
                ->attributes([
                    'maxlength' => 100,
                    'pattern' => '[a-z0-9]+(-[a-z0-9]+)*',
                    'placeholder' => filled($slug) ? $slug : 'generato dal nome se vuoto',
                ])
                ->extra('prefix', url('/a').'/')
                ->help('Solo minuscole, numeri e trattini (kebab-case).'),
            FormelloField::make('partita_iva')->label('Partita IVA')->columns(4)->attributes(['maxlength' => 20]),
            FormelloField::make('codice_fiscale')->label('Codice fiscale')->columns(4)->attributes(['maxlength' => 16]),
            FormelloField::make('telefono')->label('Telefono')->type('tel')->columns(4)->attributes(['maxlength' => 20]),
            FormelloField::make('indirizzo')->label('Indirizzo')->columns(6)->attributes(['maxlength' => 255]),
            FormelloField::make('citta')->label('Città')->columns(3)->attributes(['maxlength' => 100]),
            FormelloField::make('provincia')->label('Prov.')->columns(1)->attributes(['maxlength' => 2]),
            FormelloField::make('cap')->label('CAP')->columns(2)->attributes(['maxlength' => 5, 'inputmode' => 'numeric']),

            $tipoScadenza,
            $giorni,
            FormelloField::make('costo_tessera')->label('Costo tessera')->type('number')->columns(4)
                ->attributes(['step' => '0.01', 'min' => 0])
                ->extra('suffix', '€'),
            $attiva,

            FormelloField::make('admin_nome')->label('Nome admin')->columns(6)->attributes(['maxlength' => 100]),
            FormelloField::make('admin_cognome')->label('Cognome admin')->columns(6)->attributes(['maxlength' => 100]),
            FormelloField::make('admin_email')->label('Email admin')->type('email')->columns(6)->attributes(['maxlength' => 255]),
            FormelloField::make('admin_password')->label('Password admin')->widget('password')->columns(6)
                ->help(PasswordPolicy::hint())
                ->extra('campi_utente', ['admin_nome', 'admin_cognome', 'admin_email', 'nome']),
        ];
    }
}
