<?php

namespace App\Http\Requests\Iscrizioni;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rifiuto di una richiesta di iscrizione socio: il motivo è OBBLIGATORIO
 * (viene riportato all'account nella notifica di esito).
 * L'autorizzazione è gestita dalla RichiestaIscrizionePolicy nel controller.
 */
class RifiutaRichiestaIscrizioneRequest extends FormRequest
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
            'motivo' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.required' => 'Il motivo del rifiuto è obbligatorio: viene comunicato al richiedente.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'motivo' => 'motivo del rifiuto',
        ];
    }
}
