<?php

namespace App\Http\Requests\Economia;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Voce di spesa di un evento (f.17 del piano "Gestione 2").
 */
class StoreSpesaEventoRequest extends FormRequest
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
            'descrizione' => ['required', 'string', 'max:255'],
            'importo' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'data' => ['required', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'descrizione' => 'descrizione',
            'importo' => 'importo',
            'data' => 'data',
        ];
    }
}
