<?php

namespace App\Http\Requests\Soci;

use App\Models\Socio;
use App\Services\Soci\SociFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gruppi dinamici (spec soci §7): filtro salvato {colonna: valore} con
 * whitelist colonne; solo le chiavi non vuote finiscono nel JSON.
 */
class GruppoDinamicoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via GruppoDinamicoPolicy nel controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome_gruppo' => [
                'required', 'string', 'max:100',
                Rule::unique('gruppi_dinamici', 'nome_gruppo')
                    ->where('associazione_id', Socio::currentAssociazioneId())
                    ->ignore($this->route('gruppo')?->id),
            ],
            'descrizione' => ['nullable', 'string'],
            'filtri' => ['nullable', 'array'],
            'filtri.stato' => ['nullable', Rule::in(Socio::STATI)],
        ];
    }

    /**
     * Filtri normalizzati: solo chiavi whitelisted e valori non vuoti.
     *
     * @return array<string, string>
     */
    public function filtriJson(): array
    {
        $filtri = [];

        foreach ((array) $this->input('filtri', []) as $colonna => $valore) {
            if (in_array($colonna, SociFilter::GRUPPO_WHITELIST, true) && filled($valore) && is_scalar($valore)) {
                $filtri[$colonna] = trim((string) $valore);
            }
        }

        return $filtri;
    }
}
