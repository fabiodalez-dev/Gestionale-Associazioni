<?php

namespace App\Http\Requests\FrontendPubblico\Account;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Aggiornamento anagrafica dell'account (area riservata).
 */
class AggiornaProfiloRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('account') !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'telefono' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome',
            'cognome' => 'cognome',
            'telefono' => 'telefono',
        ];
    }
}
