<?php

namespace App\Http\Requests\Comunicazione;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Voto a un sondaggio (f.11) dalle aree riservate (guard 'socio' o
 * 'account'): indice 0-based dell'opzione scelta. La verifica che il
 * sondaggio sia aperto, l'opzione esista e il votante non abbia già
 * votato è nel SondaggiVotoService.
 */
class VotoSondaggioRequest extends FormRequest
{
    /**
     * Solo chi è autenticato come socio o come account del mini-sito può votare.
     */
    public function authorize(): bool
    {
        return $this->user('socio') !== null || $this->user('account') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'opzione' => ['required', 'integer', 'min:0', 'max:65535'],
        ];
    }
}
