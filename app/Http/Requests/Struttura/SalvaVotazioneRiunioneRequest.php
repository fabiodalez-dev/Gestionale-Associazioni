<?php

namespace App\Http\Requests\Struttura;

use App\Models\Riunione;
use App\Models\VotazioneRiunione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Registrazione/aggiornamento dell'esito di una votazione su un punto
 * dell'OdG (f.18): un esito per punto (unique a DB), conteggio
 * favorevoli/contrari/astenuti. punto_indice deve esistere nell'OdG
 * della riunione svolta.
 */
class SalvaVotazioneRiunioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $riunione = $this->route('riunione');

        return $riunione instanceof Riunione
            && ($this->user()?->can('update', $riunione) ?? false);
    }

    public function rules(): array
    {
        /** @var Riunione $riunione */
        $riunione = $this->route('riunione');

        $odg = is_array($riunione->odg) ? $riunione->odg : [];
        $maxIndice = count($odg) - 1;

        return [
            'punto_indice' => ['required', 'integer', 'min:0', 'max:'.max($maxIndice, 0)],
            'esito' => ['required', Rule::in(VotazioneRiunione::ESITI)],
            'favorevoli' => ['required', 'integer', 'min:0', 'max:65535'],
            'contrari' => ['required', 'integer', 'min:0', 'max:65535'],
            'astenuti' => ['required', 'integer', 'min:0', 'max:65535'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'punto_indice.max' => 'Il punto selezionato non esiste nell\'ordine del giorno della riunione.',
        ];
    }
}
