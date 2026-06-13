<?php

namespace App\Http\Requests\VolontariTurni;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registrazione presenze a fine turno (f.5 del piano "Organizzazione 3"):
 * per ogni assegnazione confermata, le ore effettive (precompilate dalla
 * durata del turno lato vista). Valore nullo = assenza, nessuna ora.
 */
class RegistraPresenzeRequest extends FormRequest
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
            'ore' => ['nullable', 'array'],
            'ore.*' => ['nullable', 'numeric', 'min:0', 'max:24'],
        ];
    }

    /**
     * @return array<string, float|null>
     */
    public function orePerAssegnazione(): array
    {
        $out = [];

        foreach ((array) $this->input('ore', []) as $assegnazioneId => $ore) {
            $out[(string) $assegnazioneId] = ($ore === null || $ore === '')
                ? null
                : (float) $ore;
        }

        return $out;
    }
}
