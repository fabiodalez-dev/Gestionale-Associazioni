<?php

namespace App\Http\Requests\Struttura;

use Illuminate\Validation\Rule;

class UpdateTesseramentoRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'tipo_scadenza_default' => ['required', Rule::in(['solare', 'annuale'])],
            'giorni_notifica_scadenza' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }
}
