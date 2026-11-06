<?php

namespace App\Http\Requests\EventiVita;

use App\Models\Evento;
use App\Services\Prenotazioni\CapienzaEventoService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Aggiornamento dell'INTERA serie ricorrente (f.8): solo i campi comuni
 * (SerieEventiService::CAMPI_SERIE) — mai le date, che restano proprie
 * di ogni occorrenza. L'autorizzazione (eventi.update) è nella policy.
 */
class SerieUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('prezzo')) {
            // Virgola decimale italiana, come EventoRequest
            $this->merge(['prezzo' => str_replace(',', '.', trim((string) $this->input('prezzo')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'descrizione' => ['nullable', 'string'],
            'luogo' => ['nullable', 'string', 'max:255'],
            'stato_pubblicazione' => ['required', Rule::in(Evento::STATI_PUBBLICAZIONE)],
            'capienza' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'prezzo' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'iscrizioni_aperte' => ['required', 'boolean'],
            'richiede_approvazione' => ['required', 'boolean'],
            'solo_soci' => ['required', 'boolean'],
            'immagine_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    /**
     * Aggiunge il primo controllo della capienza dopo le regole base
     * (ripetuto sotto lock al salvataggio).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validaCapienzaNonRiducibile($validator);
        });
    }

    /**
     * Stessa regola della modifica singola (EventoRequest): la capienza
     * propagata non può scendere sotto i posti già occupati di nessuna
     * occorrenza; una capienza invariata resta ammessa anche in overbooking.
     *
     * Primo controllo, fuori dal lock: SerieEventiService::aggiornaSerie
     * lo ripete con le occorrenze bloccate.
     */
    protected function validaCapienzaNonRiducibile(Validator $validator): void
    {
        $evento = $this->route('evento');
        // Stesso parsing della regola integer (es. "+1" vale 1): con
        // ctype_digit valori come "+1" saltavano il controllo dei posti
        $capienza = CapienzaEventoService::capienzaRichiesta($this->input('capienza'));

        if (! $evento instanceof Evento || $evento->serie_id === null) {
            return;
        }

        if ($capienza === null) {
            return; // illimitata o già segnalata dalla regola integer/min
        }

        $occorrenze = Evento::query()
            ->dellaSerie($evento->serie_id)
            ->where('associazione_id', $evento->associazione_id)
            ->orderBy('data_evento')
            ->get();

        foreach ($occorrenze as $occorrenza) {
            $errore = CapienzaEventoService::errore($occorrenza, $capienza, occorrenza: true);

            if ($errore !== null) {
                $validator->errors()->add('capienza', $errore);

                return;
            }
        }
    }
}
