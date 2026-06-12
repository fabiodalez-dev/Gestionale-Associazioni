<?php

namespace App\Http\Requests\Struttura;

use App\Models\Associazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAssociazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Associazione::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            // Slug mini-sito editabile (f.22): kebab-case, unique; vuoto = autogenerato dal nome
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('associazioni', 'slug')],
            'email' => ['required', 'email', 'max:255', Rule::unique('associazioni', 'email')],
            'partita_iva' => ['nullable', 'string', 'max:20'],
            'codice_fiscale' => ['nullable', 'string', 'max:16'],
            'telefono' => ['nullable', 'string', 'max:20'],
            'indirizzo' => ['nullable', 'string', 'max:500'],
            'citta' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'size:2', 'alpha'],
            'cap' => ['nullable', 'digits:5'],
            'tipo_scadenza_default' => ['nullable', Rule::in(['solare', 'annuale'])],
            'giorni_notifica_scadenza' => ['nullable', 'integer', 'min:1', 'max:365'],
            'costo_tessera' => ['nullable', 'numeric', 'min:0'],
            'attiva' => ['nullable', 'boolean'],
            'logo_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png', 'max:2048'],

            // Collegamento admin esistente (prioritario su create_admin)
            'link_admin_user_id' => ['nullable', 'uuid'],

            // Creazione admin contestuale (validata in modo bloccante, spec §9.6)
            'create_admin' => ['nullable', 'boolean'],
            'admin_nome' => ['exclude_without:create_admin', 'required', 'string', 'max:100'],
            'admin_cognome' => ['exclude_without:create_admin', 'nullable', 'string', 'max:100'],
            'admin_email' => ['exclude_without:create_admin', 'required', 'email', Rule::unique('utenti', 'email')],
            'admin_password' => ['exclude_without:create_admin', 'required', 'string', 'min:8'],

            // Sede iniziale
            'create_sede' => ['nullable', 'boolean'],
            'sede_nome' => ['nullable', 'string', 'max:255'],
            'sede_indirizzo' => ['nullable', 'string', 'max:500'],
            'sede_citta' => ['nullable', 'string', 'max:100'],
            'sede_responsabile' => ['nullable', 'string', 'max:255'],

            // Clonazione configurazioni
            'clone_enable' => ['nullable', 'boolean'],
            'clone_from_assoc_id' => ['exclude_without:clone_enable', 'required', 'uuid', Rule::exists('associazioni', 'id')],
            'clone_tipi' => ['nullable', 'boolean'],
            'clone_categorie' => ['nullable', 'boolean'],
            'clone_sedi' => ['nullable', 'boolean'],
            'clone_campi' => ['nullable', 'boolean'],
            'clone_tags' => ['nullable', 'boolean'],
            'clone_gruppi' => ['nullable', 'boolean'],
            'clone_templates' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Esiste già un\'associazione con questa email.',
            'slug.regex' => 'Lo slug deve essere in kebab-case: solo minuscole, numeri e trattini (es. "mia-associazione").',
            'slug.unique' => 'Questo slug è già usato da un\'altra associazione.',
            'admin_password.min' => 'Admin non creato: la password deve avere almeno 8 caratteri.',
            'admin_email.unique' => 'Admin non creato: email già esistente.',
            'clone_from_assoc_id.required' => 'Seleziona l\'associazione da cui clonare le configurazioni.',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Whitelist legacy: valore fuori lista → 'solare'
        if (! in_array($this->input('tipo_scadenza_default'), ['solare', 'annuale'], true)) {
            $this->merge(['tipo_scadenza_default' => 'solare']);
        }

        // Stringa vuota → NULL (semantica legacy di costo_tessera)
        if ($this->input('costo_tessera') === '') {
            $this->merge(['costo_tessera' => null]);
        }

        // Slug: trim e stringa vuota → NULL (autogenerazione dal nome)
        if ($this->has('slug')) {
            $slug = trim((string) $this->input('slug'));
            $this->merge(['slug' => $slug === '' ? null : $slug]);
        }
    }
}
