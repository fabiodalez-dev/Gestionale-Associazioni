<?php

namespace App\Http\Requests\Economia;

use App\Models\Movimento;
use App\Rules\SedeNelPerimetro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/aggiornamento di un movimento manuale di prima nota (f.13).
 * La categoria deve appartenere al tenant ED essere coerente col tipo
 * (entrata/uscita) del movimento.
 */
class SalvaMovimentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Autorizzazione gestita dalla MovimentoPolicy nel controller
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Rule::exists NON applica i global scope: vincolo di tenant esplicito
        $tenantId = session('associazione_id') ?? $this->user()?->associazione_id;

        return [
            'tipo' => ['required', Rule::in(Movimento::TIPI)],
            'importo' => ['required', 'numeric', 'gt:0', 'max:99999999.99'],
            'data' => ['required', 'date'],
            'categoria_id' => [
                'required',
                'uuid',
                Rule::exists('categorie_movimento', 'id')
                    ->where('associazione_id', $tenantId)
                    ->where('tipo', (string) $this->input('tipo')),
            ],
            'sede_id' => SedeNelPerimetro::regole($tenantId),
            'descrizione' => ['nullable', 'string', 'max:500'],
            'metodo' => ['nullable', 'string', 'max:50'],
            // f.13 piano "Organizzazione 3": imputazione opzionale a un progetto del tenant
            'progetto_id' => [
                'nullable',
                'uuid',
                Rule::exists('progetti', 'id')->where('associazione_id', $tenantId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'tipo' => 'tipo',
            'importo' => 'importo',
            'data' => 'data',
            'categoria_id' => 'categoria',
            'descrizione' => 'descrizione',
            'metodo' => 'metodo',
            'sede_id' => 'sede',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categoria_id.required' => 'Seleziona una categoria.',
            'categoria_id.exists' => 'La categoria selezionata non è valida per questo tipo di movimento.',
        ];
    }
}
