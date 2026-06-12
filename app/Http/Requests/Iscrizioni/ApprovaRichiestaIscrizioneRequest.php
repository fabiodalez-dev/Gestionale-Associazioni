<?php

namespace App\Http\Requests\Iscrizioni;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approvazione di una richiesta di iscrizione socio: generazione
 * contestuale della tessera (checkbox della modale) e delibera di
 * ammissione opzionale (numero/data, f.14 del piano vita sociale).
 * L'autorizzazione è gestita dalla RichiestaIscrizionePolicy nel controller.
 */
class ApprovaRichiestaIscrizioneRequest extends FormRequest
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
            'genera_tessera' => ['nullable', 'boolean'],
            'genera_quota' => ['nullable', 'boolean'],
            'delibera_numero' => ['nullable', 'string', 'max:50'],
            'delibera_data' => ['nullable', 'date'],
        ];
    }
}
