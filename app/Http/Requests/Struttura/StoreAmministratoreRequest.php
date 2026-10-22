<?php

namespace App\Http\Requests\Struttura;

use App\Models\Utente;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAmministratoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Utente::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('utenti', 'email')],
            'ruolo' => ['required', Rule::in(Utente::RUOLI)],
            // admin_associazione e operatore DEVONO avere un'associazione (spec §3.3.4)
            'associazione_id' => [
                'nullable', 'uuid',
                Rule::exists('associazioni', 'id'),
                Rule::requiredIf(fn () => $this->input('ruolo') !== Utente::RUOLO_SUPER_ADMIN),
            ],
            // Con invito via email la password viene generata e impostata dall'invitato
            'invia_invito' => ['nullable', 'boolean'],
            'password' => ['nullable', 'required_without:invia_invito', ...PasswordPolicy::rules([$this->input('email'), $this->input('nome'), $this->input('cognome')])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'associazione_id.required' => 'Gli admin di associazione devono essere collegati a un\'associazione!',
            ...PasswordPolicy::messages(),
            'password.required_without' => 'Inserisci una password oppure attiva l\'invito via email.',
        ];
    }
}
