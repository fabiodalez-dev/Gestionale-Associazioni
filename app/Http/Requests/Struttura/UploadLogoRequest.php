<?php

namespace App\Http\Requests\Struttura;

/**
 * Upload logo dalla pagina configurazioni (spec §4.2.2): qui l'errore è bloccante.
 */
class UploadLogoRequest extends ConfigurazioniRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'logo_file' => ['required', 'file', 'mimes:jpg,jpeg,png', 'max:2048', 'dimensions:max_width=8000,max_height=8000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo_file.required' => 'Seleziona un file per il logo.',
            'logo_file.mimes' => 'Logo non valido: sono ammessi solo file JPG e PNG.',
            'logo_file.max' => 'Logo non valido: dimensione massima 2 MB.',
            'logo_file.dimensions' => 'Logo non valido: al massimo 8000×8000 pixel.',
        ];
    }
}
