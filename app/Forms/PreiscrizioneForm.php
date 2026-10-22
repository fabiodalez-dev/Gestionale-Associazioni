<?php

namespace App\Forms;

use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Support\Collection;
use Metalogico\Formello\FormelloField;

/**
 * Pre-iscrizione pubblica di un socio (/preiscrizione/{associazione}), anche come rinnovo
 * con dati precompilati. Regole: App\Http\Requests\Struttura\PreiscrizioneRequest.
 * I campi personalizzati dell'associazione restano nella pagina (nomi custom_field[id]).
 *
 * Contesto: 'associazione', 'obbligatori' (array campo => bool, config "preiscrizione"),
 * 'tipi_socio' e 'categorie_socio' (Collection con id/nome).
 */
class PreiscrizioneForm extends ModuloFormello
{
    public const CAMPI_ANAGRAFICA = ['nome', 'cognome', 'email', 'telefono', 'data_nascita', 'codice_fiscale', 'indirizzo', 'citta', 'provincia', 'cap', 'tipo_socio_id', 'categoria_socio_id'];

    protected function fields(): array
    {
        /** @var Associazione $associazione */
        $associazione = $this->contesto['associazione'];
        $obbligatori = (array) ($this->contesto['obbligatori'] ?? []);
        $obbligatorio = fn (string $campo): bool => (bool) ($obbligatori[$campo] ?? false);
        $rinnovo = $this->isEditing();

        /** @var Collection<int, object{id: string, nome: string}> $tipi */
        $tipi = $this->contesto['tipi_socio'] ?? collect();
        /** @var Collection<int, object{id: string, nome: string}> $categorie */
        $categorie = $this->contesto['categorie_socio'] ?? collect();

        $campo = function (string $nome, string $etichetta, int $colonne, array $attributi = []) use ($obbligatorio): FormelloField {
            $f = FormelloField::make($nome)->label($etichetta)->columns($colonne)->attributes($attributi);

            return $obbligatorio($nome) ? $f->required() : $f;
        };

        $dataNascita = $campo('data_nascita', 'Data di nascita', 6)->type('date');
        $nascita = $this->model->getAttribute('data_nascita');
        if ($rinnovo && $nascita !== null) {
            $dataNascita->value($nascita->format('Y-m-d'));
        }

        $campi = [
            FormelloField::make('nome')->label('Nome')->columns(6)->required()->attributes(['maxlength' => 100, 'autocomplete' => 'given-name']),
            FormelloField::make('cognome')->label('Cognome')->columns(6)->required()->attributes(['maxlength' => 100, 'autocomplete' => 'family-name']),
            FormelloField::make('email')->label('Email')->type('email')->columns(6)->required()->attributes(['autocomplete' => 'email']),
            $campo('telefono', 'Telefono', 6, ['maxlength' => 20, 'autocomplete' => 'tel'])->type('tel'),
            $dataNascita,
            $campo('codice_fiscale', 'Codice fiscale', 6, ['maxlength' => 16, 'style' => 'text-transform: uppercase']),
            $campo('indirizzo', 'Indirizzo', 6, ['autocomplete' => 'street-address']),
            $campo('citta', 'Città', 6, ['maxlength' => 100, 'autocomplete' => 'address-level2']),
            $campo('provincia', 'Prov.', 3, ['maxlength' => 2, 'style' => 'text-transform: uppercase']),
            $campo('cap', 'CAP', 3, ['maxlength' => 5, 'inputmode' => 'numeric', 'autocomplete' => 'postal-code']),
        ];

        // Campi anagrafici nascosti dall'associazione (Configurazioni → Moduli pubblici)
        $moduli = $associazione->moduli();
        $campi = array_values(array_filter($campi, fn (FormelloField $f) => $moduli->campoVisibile($f->getName())));

        if ($tipi->isNotEmpty()) {
            $campi[] = $campo('tipo_socio_id', 'Tipo socio', 6)->widget('select')
                ->choices($tipi->pluck('nome', 'id')->all())->extra('placeholder', '-- Seleziona --');
        }
        if ($categorie->isNotEmpty()) {
            $campi[] = $campo('categoria_socio_id', 'Categoria socio', 6)->widget('select')
                ->choices($categorie->pluck('nome', 'id')->all())->extra('placeholder', '-- Seleziona --');
        }

        // Link sempre presente: senza testo personalizzato la pagina mostra l'informativa predefinita
        $privacy = FormelloField::make('privacy_consenso')->label('Accetto l\'informativa sulla privacy')
            ->widget('consenso')->required()->value($rinnovo)
            ->extra('link_url', route('privacy-policy.show', ['associazione' => $associazione->id]))
            ->extra('link_testo', '— leggi l\'informativa');
        $campi[] = $privacy;

        return $campi;
    }

    public static function nuovo(Associazione $associazione, ?Socio $socioRinnovo, array $obbligatori, Collection $tipiSocio, Collection $categorieSocio): self
    {
        return new self($socioRinnovo ?? Socio::class, [
            'associazione' => $associazione,
            'obbligatori' => $obbligatori,
            'tipi_socio' => $tipiSocio,
            'categorie_socio' => $categorieSocio,
        ]);
    }
}
