<?php

namespace App\Http\Requests\Rinnovi;

use App\Models\Tessera;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Stampa massiva tessere in PDF (f.4): filtri della lista tessere
 * oppure selezione esplicita di id. Autorizzazione: tessere.view.
 */
class StampaMassivaTessereRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Tessera::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'anno' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'search' => ['nullable', 'string', 'max:255'],
            'stato' => ['nullable', 'string', 'max:30'],
            'ids' => ['nullable', 'array', 'max:500'],
            'ids.*' => ['uuid'],
        ];
    }
}
