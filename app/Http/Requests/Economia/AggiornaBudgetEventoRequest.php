<?php

namespace App\Http\Requests\Economia;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Budget previsto dell'evento (f.17): null = budget non gestito.
 */
class AggiornaBudgetEventoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorizzazione gestita dalla EventoPolicy nel controller
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'budget' => ['nullable', 'numeric', 'gte:0', 'max:99999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'budget' => 'budget',
        ];
    }
}
