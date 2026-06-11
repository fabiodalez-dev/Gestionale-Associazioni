<?php

namespace App\Http\Requests\Iscrizioni;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Approvazione di una richiesta di iscrizione socio: l'unica opzione è
 * la generazione contestuale della tessera (checkbox della modale).
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
        ];
    }
}
