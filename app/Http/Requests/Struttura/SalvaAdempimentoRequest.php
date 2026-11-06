<?php

namespace App\Http\Requests\Struttura;

use App\Models\Adempimento;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica di un adempimento ricorrente (f.20): titolo, mese
 * di scadenza (1-12) e flag attivo. La gestione è riservata agli admin
 * (policy AdempimentoPolicy, famiglia struttura.*).
 */
class SalvaAdempimentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $adempimento = $this->route('adempimento');

        if ($adempimento instanceof Adempimento) {
            return $this->user()?->can('update', $adempimento) ?? false;
        }

        return $this->user()?->can('create', Adempimento::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Checkbox non spuntata → false (assente nel payload del form)
        $this->merge(['attivo' => $this->boolean('attivo')]);
    }

    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:200'],
            'descrizione' => ['nullable', 'string', 'max:2000'],
            'manuale_pagina_id' => ['nullable', 'uuid', Rule::exists('manuale_pagine', 'id')->where('associazione_id', session('associazione_id'))],
            'mese_scadenza' => ['required', 'integer', 'min:1', 'max:12'],
            'attivo' => ['required', 'boolean'],
        ];
    }
}
