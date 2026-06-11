<?php

namespace App\Http\Requests\DocumentiVerbaliScadenze;

use App\Models\Verbale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Aggiunta firmatario a un verbale (estensione §6.2):
 * il firmatario può essere un socio, un utente admin o un nominativo libero
 * (almeno uno dei tre); socio/utente devono appartenere al tenant corrente.
 */
class StoreVerbaleFirmatarioRequest extends FormRequest
{
    public function authorize(): bool
    {
        $verbale = $this->route('verbale');

        return $verbale instanceof Verbale
            && ($this->user()?->can('update', $verbale) ?? false);
    }

    public function rules(): array
    {
        $tenantId = session('associazione_id');

        return [
            'socio_id' => [
                'nullable',
                'uuid',
                'required_without_all:utente_id,nome',
                Rule::exists('soci', 'id')->where('associazione_id', $tenantId),
            ],
            'utente_id' => [
                'nullable',
                'uuid',
                Rule::exists('utenti', 'id')->where('associazione_id', $tenantId),
            ],
            'nome' => ['nullable', 'string', 'max:255'],
            'ruolo' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return [
            'socio_id.required_without_all' => 'Indica un socio, un utente o un nominativo per il firmatario.',
            'socio_id.exists' => 'Il socio selezionato non appartiene all\'associazione corrente.',
            'utente_id.exists' => 'L\'utente selezionato non appartiene all\'associazione corrente.',
        ];
    }
}
