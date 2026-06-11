<?php

namespace App\Http\Requests\Quote;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreQuotaRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorizzazione gestita dalla QuotaPolicy nel controller
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Rule::exists NON applica i global scope: il vincolo di tenant va espresso qui
        $tenantId = session('associazione_id') ?? $this->user()?->associazione_id;

        return [
            'socio_id' => [
                'required',
                'uuid',
                Rule::exists('soci', 'id')
                    ->where('associazione_id', $tenantId)
                    ->whereNull('deleted_at'),
            ],
            'anno' => ['required', 'integer', 'between:2000,2100'],
            'importo' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'data_scadenza' => ['required', 'date'],
            'tipo' => ['required', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'socio_id' => 'socio',
            'anno' => 'anno',
            'importo' => 'importo',
            'data_scadenza' => 'data di scadenza',
            'tipo' => 'tipo',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socio_id.required' => 'Seleziona un socio.',
            'socio_id.exists' => 'Il socio selezionato non esiste in questa associazione.',
        ];
    }
}
