<?php

namespace App\Http\Requests\Operativita;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creazione nota interna sul socio (f.3 del piano "Gestione 2").
 */
class StoreNotaSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via NotaSocioPolicy nel controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'testo' => ['required', 'string', 'max:5000'],
            'fissata' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['testo' => 'testo della nota'];
    }
}
