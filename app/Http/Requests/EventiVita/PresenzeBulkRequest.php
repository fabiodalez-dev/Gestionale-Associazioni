<?php

namespace App\Http\Requests\EventiVita;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registrazione presenze manuale in blocco (f.9): checkbox multipli
 * sulle prenotazioni confermate dell'evento. Il vincolo "prenotazione
 * dell'evento e del tenant" è applicato dal controller con una UPDATE
 * scoped (gli id estranei vengono semplicemente ignorati).
 */
class PresenzeBulkRequest extends FormRequest
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
            'prenotazioni' => ['required', 'array', 'min:1'],
            'prenotazioni.*' => ['uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'prenotazioni.required' => 'Seleziona almeno una prenotazione.',
            'prenotazioni.min' => 'Seleziona almeno una prenotazione.',
        ];
    }
}
