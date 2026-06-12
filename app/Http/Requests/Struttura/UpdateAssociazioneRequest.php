<?php

namespace App\Http\Requests\Struttura;

use App\Models\Associazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAssociazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $associazione = $this->route('associazione');

        return $associazione instanceof Associazione
            && ($this->user()?->can('update', $associazione) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Associazione $associazione */
        $associazione = $this->route('associazione');

        return [
            'nome' => ['required', 'string', 'max:255'],
            // Slug mini-sito editabile (f.22): kebab-case, unique; vuoto = slug corrente invariato
            'slug' => ['nullable', 'string', 'max:100', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', Rule::unique('associazioni', 'slug')->ignore($associazione->id)],
            'email' => ['required', 'email', 'max:255', Rule::unique('associazioni', 'email')->ignore($associazione->id)],
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

            'link_admin_user_id' => ['nullable', 'uuid'],

            'create_admin' => ['nullable', 'boolean'],
            'admin_nome' => ['exclude_without:create_admin', 'required', 'string', 'max:100'],
            'admin_cognome' => ['exclude_without:create_admin', 'nullable', 'string', 'max:100'],
            'admin_email' => ['exclude_without:create_admin', 'required', 'email', Rule::unique('utenti', 'email')],
            'admin_password' => ['exclude_without:create_admin', 'required', 'string', 'min:8'],

            'clone_enable' => ['nullable', 'boolean'],
            'clone_from_assoc_id' => [
                'exclude_without:clone_enable', 'required', 'uuid',
                Rule::exists('associazioni', 'id'),
                Rule::notIn([$associazione->id]),
            ],
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
            'clone_from_assoc_id.not_in' => 'Non puoi clonare le configurazioni dall\'associazione stessa.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (! in_array($this->input('tipo_scadenza_default'), ['solare', 'annuale'], true)) {
            $this->merge(['tipo_scadenza_default' => 'solare']);
        }

        if ($this->input('costo_tessera') === '') {
            $this->merge(['costo_tessera' => null]);
        }

        // Slug: trim e stringa vuota → NULL (slug corrente invariato)
        if ($this->has('slug')) {
            $slug = trim((string) $this->input('slug'));
            $this->merge(['slug' => $slug === '' ? null : $slug]);
        }
    }
}
