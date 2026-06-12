<?php

namespace App\Http\Requests\Soci;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload documento dal dettaglio socio (spec soci §10.2, tab Documenti).
 */
class UploadDocumentoSocioRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // autorizzazione via SocioPolicy nel controller
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required', 'file', 'max:10240',
                'mimes:pdf,jpg,jpeg,png,gif,webp,doc,docx,xls,xlsx,odt,ods,txt,csv',
            ],
            'descrizione' => ['nullable', 'string', 'max:1000'],
            // Tipo documento (f.23): string libera, suggerimenti in DocumentoSocio::TIPI_DOCUMENTO
            'tipo_documento' => ['nullable', 'string', 'max:100'],
            'data_scadenza' => ['nullable', 'date'],
        ];
    }
}
