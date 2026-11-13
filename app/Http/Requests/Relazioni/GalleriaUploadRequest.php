<?php

namespace App\Http\Requests\Relazioni;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Upload di foto nella galleria di un evento (f.24 del piano
 * "Organizzazione 3"): immagini jpeg/png/webp, max 5MB e 8000×8000 pixel
 * ciascuna (oltre, la decodifica per le miniature esaurisce la memoria).
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
            'foto.*' => ['image', 'mimes:jpeg,png,webp', 'max:5120', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'foto.*.dimensions' => 'Ogni foto può misurare al massimo 8000×8000 pixel.',
        ];
    }
}
