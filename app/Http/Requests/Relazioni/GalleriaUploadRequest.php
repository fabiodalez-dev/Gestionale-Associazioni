<?php

namespace App\Http\Requests\Relazioni;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload di foto nella galleria di un evento (f.24 del piano
 * "Organizzazione 3"): immagini jpeg/png/webp, max 5MB ciascuna.
 * Autorizzazione via EventoPolicy nel controller.
 */
class GalleriaUploadRequest extends FormRequest
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
            'foto' => ['required', 'array', 'min:1', 'max:20'],
            'foto.*' => ['image', 'mimes:jpeg,png,webp', 'max:5120'],
        ];
    }
}
