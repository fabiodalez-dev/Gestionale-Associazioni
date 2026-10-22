<?php

namespace App\Http\Requests\FrontendPubblico\Account;

use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;

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
            'password' => ['required', 'confirmed', 'different:password_corrente', ...PasswordPolicy::rules([$this->user('account')?->email, $this->user('account')?->nome, $this->user('account')?->cognome])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password_corrente.current_password' => 'La password corrente non è corretta.',
            'password.different' => 'La nuova password deve essere diversa da quella attuale.',
            ...PasswordPolicy::messages(),
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
