<?php

namespace App\Http\Requests\DocumentiVerbaliScadenze;

use App\Models\Verbale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica verbale (spec §2.3 F1 + §4).
 * L'autorizzazione fine (update di verbali approvati) è gestita dalla VerbalePolicy
 * nel controller; qui si verifica solo la capability di base.
 */
class SalvaVerbaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $verbale = $this->route('verbale');

        if ($verbale instanceof Verbale) {
            return $this->user()?->can('update', $verbale) ?? false;
        }

        return $this->user()?->can('create', Verbale::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'data_riunione' => ['required', 'date'],
            'tipo_riunione' => ['required', Rule::in(Verbale::TIPI_RIUNIONE)],
            'presenti' => ['nullable', 'string'],
            'ordine_del_giorno' => ['nullable', 'string'],
            'deliberazioni' => ['nullable', 'string'],
            // Collegamento opzionale a un evento del tenant, es. assemblea (f.17)
            'evento_id' => [
                'nullable',
                'uuid',
                Rule::exists('eventi', 'id')->where('associazione_id', session('associazione_id')),
            ],
        ];
    }

    public function attributes(): array
    {
        return [
            'titolo' => 'titolo',
            'data_riunione' => 'data riunione',
            'tipo_riunione' => 'tipo riunione',
            'evento_id' => 'evento collegato',
        ];
    }

    public function messages(): array
    {
        return [
            'evento_id.exists' => 'L\'evento selezionato non appartiene all\'associazione corrente.',
        ];
    }
}
