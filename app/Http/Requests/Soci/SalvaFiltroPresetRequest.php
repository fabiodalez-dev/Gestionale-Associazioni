<?php

namespace App\Http\Requests\Soci;

use App\Services\Soci\SociFilter;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Salvataggio preset filtri soci (spec soci §11): serializza i parametri
 * filtro correnti; nome uguale = upsert del preset personale.
 */
class SalvaFiltroPresetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via SavedFilterPolicy nel controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'preset_name' => ['required', 'string', 'max:255'],
            'condiviso' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Parametri filtro da serializzare nel preset.
     *
     * @return array<string, mixed>
     */
    public function paramsFiltro(): array
    {
        $params = [];

        foreach (array_keys(SociFilter::DEFAULTS) as $chiave) {
            if ($this->has($chiave)) {
                $params[$chiave] = $this->input($chiave);
            }
        }

        return $params;
    }
}
