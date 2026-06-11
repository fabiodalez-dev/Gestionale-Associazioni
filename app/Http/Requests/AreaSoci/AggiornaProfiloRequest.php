<?php

namespace App\Http\Requests\AreaSoci;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Il socio può modificare SOLO i recapiti (come nel legacy: telefono,
 * indirizzo, citta, cap) più la preferenza email_opt_out (miglioramento).
 * Tutto il resto (nome, cognome, email, numero socio, stato, ...) è
 * read-only lato server: qualunque altro campo inviato viene ignorato.
 */
class AggiornaProfiloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('socio') !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'telefono' => ['nullable', 'string', 'max:20'],
            'indirizzo' => ['nullable', 'string', 'max:255'],
            'citta' => ['nullable', 'string', 'max:100'],
            'cap' => ['nullable', 'string', 'regex:/^\d{5}$/'],
            'email_opt_out' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cap.regex' => 'Il CAP deve essere composto da 5 cifre.',
        ];
    }
}
