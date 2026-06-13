<?php

namespace App\Http\Requests\SpaziRisorse;

use App\Models\Attrezzatura;
use App\Models\Spazio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validazione creazione/modifica attrezzatura dell'inventario
 * (f.9 piano "Organizzazione 3").
 */
class AttrezzaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'spazio_id' => $this->filled('spazio_id') ? $this->input('spazio_id') : null,
            'valore_unitario' => $this->filled('valore_unitario')
                ? str_replace(',', '.', trim((string) $this->input('valore_unitario')))
                : null,
            'quantita' => $this->filled('quantita') ? $this->input('quantita') : 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:150'],
            'descrizione' => ['nullable', 'string', 'max:5000'],
            'quantita' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'valore_unitario' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'stato' => ['required', Rule::in(Attrezzatura::STATI)],
            'spazio_id' => [
                'nullable',
                'uuid',
                Rule::exists((new Spazio)->getTable(), 'id')
                    ->where('associazione_id', Attrezzatura::currentAssociazioneId()),
            ],
            'ubicazione' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome',
            'descrizione' => 'descrizione',
            'quantita' => 'quantità',
            'valore_unitario' => 'valore unitario',
            'stato' => 'stato',
            'spazio_id' => 'spazio',
            'ubicazione' => 'ubicazione',
        ];
    }
}
