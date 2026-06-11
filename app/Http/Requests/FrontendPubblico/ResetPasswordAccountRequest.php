<?php

namespace App\Http\Requests\FrontendPubblico;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

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
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }
}
