<?php

namespace App\Http\Requests\Rinnovi;

use App\Models\Quota;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Generazione bulk delle quote del nuovo anno sociale (f.5).
 * Autorizzazione: creazione quote (QuotaPolicy::create).
 */
class GeneraAperturaAnnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Quota::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'anno' => ['required', 'integer', 'min:2000', 'max:2100'],
        ];
    }
}
