<?php

namespace App\Http\Requests\Struttura;

use Illuminate\Validation\Rule;

class SalvaTesseraTemplateRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $associazione = $this->associazioneCorrente();

        return [
            // Vuoto = template default per tutti i tipi (tipo_socio_id NULL)
            'tipo_id' => [
                'nullable', 'uuid',
                Rule::exists('tipi_socio', 'id')->where('associazione_id', $associazione?->id),
            ],
            'contenuto' => ['required', 'string', 'max:65535'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('tipo_id') === '') {
            $this->merge(['tipo_id' => null]);
        }
    }
}
