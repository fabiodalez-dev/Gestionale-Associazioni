<?php

namespace App\Http\Requests\Progetti;

use App\Models\Progetto;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/aggiornamento di un progetto (f.12 del piano "Organizzazione 3").
 * Il responsabile, se indicato, deve essere un utente amministratore del
 * tenant corrente (Rule::exists non applica i global scope: tenant esplicito).
 */
class ProgettoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorizzazione gestita dalla ProgettoPolicy nel controller
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = session('associazione_id') ?? $this->user()?->associazione_id;

        return [
            'nome' => ['required', 'string', 'max:200'],
            'descrizione' => ['nullable', 'string', 'max:5000'],
            'dal' => ['nullable', 'date'],
            'al' => ['nullable', 'date', 'after_or_equal:dal'],
            'responsabile_id' => [
                'nullable',
                'uuid',
                Rule::exists('utenti', 'id')->where('associazione_id', $tenantId),
            ],
            'stato' => ['required', Rule::in(Progetto::STATI)],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
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
            'dal' => 'data inizio',
            'al' => 'data fine',
            'responsabile_id' => 'responsabile',
            'stato' => 'stato',
            'budget' => 'budget',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'responsabile_id.exists' => 'Il responsabile selezionato non è valido.',
            'al.after_or_equal' => 'La data di fine non può precedere la data di inizio.',
        ];
    }
}
