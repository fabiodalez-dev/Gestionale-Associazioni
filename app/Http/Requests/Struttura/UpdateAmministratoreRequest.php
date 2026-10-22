<?php

namespace App\Http\Requests\Struttura;

use App\Models\Utente;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAmministratoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('utente');

        return $target instanceof Utente
            && ($this->user()?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Utente $target */
        $target = $this->route('utente');

        return [
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', Rule::unique('utenti', 'email')->ignore($target->id)],
            'ruolo' => ['required', Rule::in(Utente::RUOLI)],
            // Rivalidato anche in edit (lacuna legacy corretta, spec §3.4)
            'associazione_id' => [
                'nullable', 'uuid',
                Rule::exists('associazioni', 'id'),
                Rule::requiredIf(fn () => $this->input('ruolo') !== Utente::RUOLO_SUPER_ADMIN),
            ],
            // Password opzionale: vuota = invariata
            'password' => ['nullable', ...PasswordPolicy::rules([$this->input('email'), $this->input('nome'), $this->input('cognome')])],
            'attivo' => ['nullable', 'boolean'],
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
        ];
    }
}
