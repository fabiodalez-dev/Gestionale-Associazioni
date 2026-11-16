<?php

namespace App\Http\Requests\DocumentiVerbaliScadenze;

use App\Models\Documento;
use App\Models\DocumentoSocio;
use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload documento del fascicolo socio (estensione §6.1):
 * il socio deve appartenere al tenant corrente.
 */
class StoreDocumentoSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DocumentoSocio::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'socio_id' => [
                'required',
                'uuid',
                // Query Eloquent: applica scope tenant e scope sede (Rule::exists li ignora)
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! Socio::query()->whereKey($value)->exists()) {
                        $fail('Il socio selezionato non esiste o non appartiene all\'associazione corrente.');
                    }
                },
            ],
            'document_file' => [
                'required',
                'file',
                'max:10240',
                'mimes:'.implode(',', Documento::ESTENSIONI_PERMESSE),
            ],
            'descrizione' => ['nullable', 'string'],
            'data_scadenza' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'socio_id' => 'socio',
            'document_file' => 'file',
        ];
    }

    public function messages(): array
    {
        return [
            'document_file.max' => 'Errore: file troppo grande (max 10MB).',
            'document_file.mimes' => 'Formato file non consentito. Formati permessi: '.implode(', ', Documento::ESTENSIONI_PERMESSE).'.',
            'socio_id.exists' => 'Il socio selezionato non esiste o non appartiene all\'associazione corrente.',
        ];
    }
}
