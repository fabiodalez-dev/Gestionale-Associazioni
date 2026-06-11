<?php

namespace App\Http\Requests\Eventi;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validazione creazione/modifica evento.
 */
class EventoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'autorizzazione è gestita dalla EventoPolicy nel controller
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'data_evento' => ['required', 'date'],
            'luogo' => ['nullable', 'string', 'max:255'],
            'descrizione' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'titolo' => 'titolo',
            'data_evento' => 'data e ora',
            'luogo' => 'luogo',
            'descrizione' => 'descrizione',
        ];
    }
}
