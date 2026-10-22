<?php

namespace App\Http\Requests\FrontendPubblico;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Conferma del reset password (broker 'accounts'): token + email +
 * nuova password con gli stessi requisiti della registrazione.
 */
class ResetPasswordAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'confirmed', ...PasswordPolicy::rules([$this->input('email')])],
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
