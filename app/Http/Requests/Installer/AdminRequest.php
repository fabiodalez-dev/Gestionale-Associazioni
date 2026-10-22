<?php

namespace App\Http\Requests\Installer;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'associazione_nome' => ['required', 'string', 'max:255'],
            'associazione_email' => ['required', 'email', 'max:255'],
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules([$this->input('email'), $this->input('nome'), $this->input('cognome'), $this->input('associazione_email')])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return PasswordPolicy::messages();
    }
}
