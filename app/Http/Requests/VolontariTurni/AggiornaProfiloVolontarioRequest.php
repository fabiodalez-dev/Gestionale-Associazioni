<?php

namespace App\Http\Requests\VolontariTurni;

use App\Models\Mansione;
use App\Services\VolontariTurni\Disponibilita;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aggiornamento del profilo volontario di un socio dalla scheda admin
 * (f.1/f.2 del piano "Organizzazione 3"): flag volontario_attivo,
 * disponibilità (giorni/fasce) e mansioni assegnate. L'autorizzazione è
 * verificata via SocioPolicy::update nel controller.
 */
class AggiornaProfiloVolontarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'volontario_attivo' => ['nullable', 'boolean'],
            'disponibilita' => ['nullable', 'array'],
            'disponibilita.*' => ['array'],
            'disponibilita.*.*' => [Rule::in(array_keys(Disponibilita::FASCE))],
            'mansioni' => ['nullable', 'array'],
            'mansioni.*' => ['uuid'],
        ];
    }

    /**
     * Disponibilità normalizzata pronta per il salvataggio.
     *
     * @return array<string, list<string>>
     */
    public function disponibilitaNormalizzata(): array
    {
        return Disponibilita::normalizza($this->input('disponibilita'));
    }

    /**
     * Mansioni valide del tenant (il global scope esclude gli altri tenant).
     *
     * @return list<string>
     */
    public function mansioniValide(): array
    {
        $ids = array_values(array_filter((array) $this->input('mansioni', [])));

        if ($ids === []) {
            return [];
        }

        return Mansione::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->all();
    }
}
