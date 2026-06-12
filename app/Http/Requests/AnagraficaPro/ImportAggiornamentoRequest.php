<?php

namespace App\Http\Requests\AnagraficaPro;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload del file di aggiornamento soci (f.25): l'autorizzazione
 * granulare (SocioPolicy) è nel controller.
 */
class ImportAggiornamentoRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
        ];
    }
}
