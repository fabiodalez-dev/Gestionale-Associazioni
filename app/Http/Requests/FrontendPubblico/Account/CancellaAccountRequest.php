<?php

namespace App\Http\Requests\FrontendPubblico\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Cancellazione GDPR dell'account: conferma con la password corrente
 * (current_password sulla guard 'account') prima dell'anonimizzazione.
 */
class CancellaAccountRequest extends FormRequest
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
            'password' => ['required', 'string', 'current_password:account'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.current_password' => 'La password non è corretta: cancellazione non eseguita.',
        ];
    }
}
