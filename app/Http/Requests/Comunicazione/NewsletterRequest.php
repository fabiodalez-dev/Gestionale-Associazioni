<?php

namespace App\Http\Requests\Comunicazione;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Composizione newsletter da annunci (f.8): titolo (oggetto dell'email)
 * + selezione di annunci pubblicati del tenant. La verifica che gli
 * annunci siano pubblicati e del tenant corrente è nel NewsletterService.
 * L'autorizzazione (email.create) è nella NewsletterInviataPolicy::send.
 */
class NewsletterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'annunci' => ['required', 'array', 'min:1', 'max:20'],
            'annunci.*' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'annunci.required' => 'Seleziona almeno un annuncio pubblicato da includere.',
            'annunci.min' => 'Seleziona almeno un annuncio pubblicato da includere.',
        ];
    }
}
