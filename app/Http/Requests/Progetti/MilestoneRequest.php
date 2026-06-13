<?php

namespace App\Http\Requests\Progetti;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Creazione/aggiornamento di una milestone di progetto (f.16): titolo,
 * scadenza opzionale e ordine manuale nella checklist. Il legame al
 * progetto e il tenant sono imposti dal controller (route + sessione).
 */
class MilestoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorizzazione gestita dalla ProgettoPolicy (update sul progetto)
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:200'],
            'scadenza' => ['nullable', 'date'],
            'ordine' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'titolo' => 'titolo',
            'scadenza' => 'scadenza',
            'ordine' => 'ordine',
        ];
    }
}
