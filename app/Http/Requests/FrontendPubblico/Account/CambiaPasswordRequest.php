<?php

namespace App\Http\Requests\FrontendPubblico\Account;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Cambio password dall'area riservata: richiede la password CORRENTE
 * (current_password sulla guard 'account') e una nuova password con gli
 * stessi requisiti della registrazione (min 10, lettere+numeri, confermata).
 */
class CambiaPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('account') !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'password_corrente' => ['required', 'string', 'current_password:account'],
            'password' => ['required', 'string', 'confirmed', Password::min(10)->letters()->numbers()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password_corrente.current_password' => 'La password corrente non è corretta.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'password_corrente' => 'password corrente',
            'password' => 'nuova password',
        ];
    }
}
