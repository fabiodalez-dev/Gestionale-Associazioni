<?php

namespace App\Http\Requests\SpaziRisorse;

use App\Models\Sede;
use App\Models\Spazio;
use App\Rules\SedeNelPerimetro;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validazione creazione/modifica spazio (f.7 piano "Organizzazione 3").
 *
 * L'autorizzazione è delegata alla SpazioPolicy nel controller; sede_id è
 * vincolato a una sede DELLO STESSO tenant (la regola exists è scopata su
 * associazione_id, perché Rule::exists ignora il global scope Eloquent).
 */
class SpazioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'attivo' => $this->has('attivo') ? $this->boolean('attivo') : true,
            'sede_id' => $this->filled('sede_id') ? $this->input('sede_id') : null,
            'capienza' => $this->filled('capienza') ? $this->input('capienza') : null,
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
            'capienza' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            // Solo sedi del tenant e, per gli utenti limitati, una delle loro
            'sede_id' => SedeNelPerimetro::regole(Spazio::currentAssociazioneId()),
            'attivo' => ['required', 'boolean'],
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
            'capienza' => 'capienza',
            'sede_id' => 'sede',
            'attivo' => 'attivo',
        ];
    }
}
