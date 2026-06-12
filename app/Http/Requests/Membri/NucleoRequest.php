<?php

namespace App\Http\Requests\Membri;

use App\Models\Nucleo;
use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica nucleo familiare (f.20 — piano "Gestione 2"):
 * nome obbligatorio, capofamiglia opzionale tra i soci del tenant.
 */
class NucleoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via NucleoPolicy nel controller
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:150'],
            'capofamiglia_id' => [
                'nullable',
                Rule::exists('soci', 'id')->where('associazione_id', $this->associazioneId()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome del nucleo',
            'capofamiglia_id' => 'capofamiglia',
        ];
    }

    private function associazioneId(): ?string
    {
        $nucleo = $this->route('nucleo');

        return $nucleo instanceof Nucleo
            ? $nucleo->associazione_id
            : Socio::currentAssociazioneId();
    }
}
