<?php

namespace App\Http\Requests\Struttura;

/**
 * Crea/modifica sede del tenant corrente (spec §2.2).
 */
class SalvaSedeRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'id' => ['nullable', 'uuid'],
            'nome' => ['required', 'string', 'max:255'],
            'indirizzo' => ['nullable', 'string', 'max:500'],
            'citta' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'size:2', 'alpha'],
            'cap' => ['nullable', 'digits:5'],
            'responsabile' => ['nullable', 'string', 'max:255'],
        ];
    }
}
