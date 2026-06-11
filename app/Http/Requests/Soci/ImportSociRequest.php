<?php

namespace App\Http\Requests\Soci;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Import soci da Excel/CSV (miglioramento 12).
 */
class ImportSociRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:10240', 'mimes:xlsx,xls,csv,txt'],
        ];
    }
}
