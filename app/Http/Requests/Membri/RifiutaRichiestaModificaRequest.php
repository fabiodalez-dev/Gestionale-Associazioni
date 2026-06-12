<?php

namespace App\Http\Requests\Membri;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Rifiuto di una richiesta di modifica dati (f.19): motivo obbligatorio,
 * comunicato al socio nella pagina di stato dell'area soci/account.
 */
class RifiutaRichiestaModificaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via RichiestaModificaDatiPolicy nel controller
    }

    /**
     * @return array<string, list<string>>
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
            'motivo.required' => 'Indica il motivo del rifiuto: verrà mostrato al socio.',
        ];
    }
}
