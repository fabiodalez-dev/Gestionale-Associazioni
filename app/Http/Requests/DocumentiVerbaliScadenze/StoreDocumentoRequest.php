<?php

namespace App\Http\Requests\DocumentiVerbaliScadenze;

use App\Models\Documento;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload documento associazione (spec §1.3 F1 + §4):
 * max 10MB, whitelist estensioni con verifica del MIME reale (regola mimes).
 */
class StoreDocumentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Documento::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'document_file' => [
                'required',
                'file',
                'max:10240', // 10 MB
                'mimes:'.implode(',', Documento::ESTENSIONI_PERMESSE),
            ],
            'descrizione' => ['nullable', 'string'],
            'categoria' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function attributes(): array
    {
        return [
            'document_file' => 'file',
        ];
    }

    public function messages(): array
    {
        return [
            'document_file.max' => 'Errore: file troppo grande (max 10MB).',
            'document_file.mimes' => 'Formato file non consentito. Formati permessi: '.implode(', ', Documento::ESTENSIONI_PERMESSE).'.',
        ];
    }
}
