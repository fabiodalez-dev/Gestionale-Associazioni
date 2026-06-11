<?php

namespace App\Http\Requests\DocumentiVerbaliScadenze;

use App\Models\Scadenza;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica scadenza (spec §3.3 F1 + §4):
 * validazione server-side completa (assente nel legacy) e vincolo
 * che l'assegnatario appartenga al tenant corrente.
 */
class SalvaScadenzaRequest extends FormRequest
{
    public function authorize(): bool
    {
        $scadenza = $this->route('scadenza');

        if ($scadenza instanceof Scadenza) {
            return $this->user()?->can('update', $scadenza) ?? false;
        }

        return $this->user()?->can('create', Scadenza::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        // Stringa vuota dal form → NULL (comportamento legacy "?: null")
        if ($this->input('assegnato_a') === '') {
            $this->merge(['assegnato_a' => null]);
        }
    }

    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'descrizione' => ['nullable', 'string'],
            'data_scadenza' => ['required', 'date'],
            'tipo_scadenza' => ['required', Rule::in(Scadenza::TIPI)],
            'priorita' => ['required', Rule::in(Scadenza::PRIORITA)],
            'stato' => ['required', Rule::in(Scadenza::STATI)],
            'assegnato_a' => [
                'nullable',
                'uuid',
                Rule::exists('utenti', 'id')->where('associazione_id', session('associazione_id')),
            ],
            'promemoria_giorni' => ['required', 'integer', 'min:1', 'max:365'],
        ];
    }

    public function messages(): array
    {
        return [
            'assegnato_a.exists' => 'L\'utente assegnatario non appartiene all\'associazione corrente.',
        ];
    }
}
