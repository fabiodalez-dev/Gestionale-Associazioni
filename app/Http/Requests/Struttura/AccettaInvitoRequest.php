<?php

namespace App\Http\Requests\Struttura;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Accettazione invito amministratore: impostazione della password
 * tramite link firmato ricevuto via email.
 */
class AccettaInvitoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la validità del link è garantita dal middleware 'signed'
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Inserisci la nuova password.',
            'password.min' => 'La password deve avere almeno 8 caratteri.',
            'password.confirmed' => 'Le due password non coincidono.',
        ];
    }
}
