<?php

namespace App\Http\Requests\AnagraficaPro;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Unione di due soci duplicati (f.24): l'autorizzazione granulare
 * (SocioPolicy update sul principale + delete sul duplicato) è nel controller,
 * dove i soci sono già risolti col global scope tenant.
 */
class MergeSociRequest extends FormRequest
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
            'principale_id' => ['required', 'uuid', 'different:duplicato_id'],
            'duplicato_id' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'principale_id.different' => 'Il socio principale e il duplicato devono essere diversi.',
        ];
    }
}
