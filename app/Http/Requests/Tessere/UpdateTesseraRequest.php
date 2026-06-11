<?php

namespace App\Http\Requests\Tessere;

use App\Models\Tessera;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTesseraRequest extends FormRequest
{
    public function authorize(): bool
    {
        $tessera = $this->route('tessera');

        return $tessera instanceof Tessera
            && ($this->user()?->can('update', $tessera) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $associazioneId = (string) session('associazione_id');
        /** @var Tessera $tessera */
        $tessera = $this->route('tessera');

        return [
            // Il form di modifica permette la riassegnazione del socio (spec §9.14)
            'socio_id' => [
                'required',
                'uuid',
                Rule::exists('soci', 'id')
                    ->where('associazione_id', $associazioneId)
                    ->whereNull('deleted_at'),
            ],
            'numero_tessera' => [
                'required',
                'string',
                'max:50',
                Rule::unique('tessere', 'numero_tessera')
                    ->where('associazione_id', $associazioneId)
                    ->ignore($tessera->id),
            ],
            'anno_validita' => ['required', 'integer', 'between:2000,2100'],
            'data_emissione' => ['required', 'date'],
            'data_scadenza' => ['nullable', 'date', 'after_or_equal:data_emissione'],
            'stato' => ['required', Rule::in([Tessera::STATO_ATTIVA, Tessera::STATO_SOSPESA, Tessera::STATO_ANNULLATA])],
            'evento_creazione_id' => [
                'nullable',
                'uuid',
                Rule::exists('eventi', 'id')
                    ->where('associazione_id', $associazioneId)
                    ->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'socio_id.exists' => 'Socio non valido per questa associazione.',
            'numero_tessera.unique' => 'Numero tessera già esistente per questa associazione.',
            'evento_creazione_id.exists' => 'Evento non valido per questa associazione.',
            'stato.in' => 'Stato non valido: "Scaduta" è derivato dalla data di scadenza.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->numero_tessera)) {
            $this->merge([
                'numero_tessera' => trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', $this->numero_tessera)),
            ]);
        }

        if ($this->evento_creazione_id === '') {
            $this->merge(['evento_creazione_id' => null]);
        }
    }
}
