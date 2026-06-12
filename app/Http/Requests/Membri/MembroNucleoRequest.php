<?php

namespace App\Http\Requests\Membri;

use App\Models\Nucleo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aggiunta di un membro a un nucleo familiare (f.20): il socio deve
 * appartenere allo stesso tenant del nucleo.
 */
class MembroNucleoRequest extends FormRequest
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
        $nucleo = $this->route('nucleo');
        $associazioneId = $nucleo instanceof Nucleo ? $nucleo->associazione_id : null;

        return [
            'socio_id' => [
                'required',
                Rule::exists('soci', 'id')->where('associazione_id', $associazioneId),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['socio_id' => 'socio'];
    }
}
