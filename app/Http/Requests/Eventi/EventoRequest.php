<?php

namespace App\Http\Requests\Eventi;

use App\Models\Evento;
use App\Models\Prenotazione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validazione creazione/modifica evento, inclusa la sezione
 * "Pubblicazione frontend" (docs/PIANO-PRENOTAZIONI.md, sezione Admin).
 *
 * Regola chiave: in modifica la capienza non è riducibile sotto i posti
 * già occupati (SUM(posti) delle prenotazioni confermate + riservate).
 */
class EventoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'autorizzazione è gestita dalla EventoPolicy nel controller
        return true;
    }

    /**
     * Default per i campi di pubblicazione quando il form non li invia
     * (compatibilità con i client legacy che postano solo i campi base):
     * in modifica vale il valore corrente dell'evento (nessun reset
     * involontario, es. pubblicato → bozza), in creazione il default.
     */
    protected function prepareForValidation(): void
    {
        $evento = $this->route('evento');
        $evento = $evento instanceof Evento ? $evento : null;

        $this->merge([
            'stato_pubblicazione' => $this->filled('stato_pubblicazione')
                ? $this->input('stato_pubblicazione')
                : ($evento->stato_pubblicazione ?? Evento::STATO_BOZZA),
            // Prezzo: accetta la virgola decimale italiana
            'prezzo' => $this->filled('prezzo')
                ? str_replace(',', '.', trim((string) $this->input('prezzo')))
                : ($evento->prezzo ?? 0),
            'max_posti_per_prenotazione' => $this->filled('max_posti_per_prenotazione')
                ? $this->input('max_posti_per_prenotazione')
                : ($evento->max_posti_per_prenotazione ?? 4),
            'annullamento_entro_ore' => $this->filled('annullamento_entro_ore')
                ? $this->input('annullamento_entro_ore')
                : ($evento->annullamento_entro_ore ?? 24),
            // Checkbox: presente nel form (hidden 0 + checkbox 1); default se assente
            'lista_attesa_attiva' => $this->has('lista_attesa_attiva')
                ? $this->boolean('lista_attesa_attiva')
                : ($evento->lista_attesa_attiva ?? true),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:255'],
            'data_evento' => ['required', 'date'],
            'data_fine' => ['nullable', 'date', 'after_or_equal:data_evento'],
            'luogo' => ['nullable', 'string', 'max:255'],
            'descrizione' => ['nullable', 'string'],

            // Sezione "Pubblicazione frontend"
            'stato_pubblicazione' => ['required', Rule::in(Evento::STATI_PUBBLICAZIONE)],
            'capienza' => ['nullable', 'integer', 'min:1', 'max:4294967295'],
            'prezzo' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'iscrizioni_dal' => ['nullable', 'date'],
            'iscrizioni_al' => ['nullable', 'date'],
            'max_posti_per_prenotazione' => ['required', 'integer', 'min:1', 'max:255'],
            'lista_attesa_attiva' => ['required', 'boolean'],
            'annullamento_entro_ore' => ['required', 'integer', 'min:0', 'max:65535'],
            'immagine_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validaFinestraIscrizioni($validator);
            $this->validaCapienzaNonRiducibile($validator);
        });
    }

    /**
     * iscrizioni_al non può precedere iscrizioni_dal (controllo manuale:
     * after_or_equal fallirebbe quando iscrizioni_dal è vuoto).
     */
    protected function validaFinestraIscrizioni(Validator $validator): void
    {
        $dal = $this->date('iscrizioni_dal');
        $al = $this->date('iscrizioni_al');

        if ($dal !== null && $al !== null && $al->lessThan($dal)) {
            $validator->errors()->add(
                'iscrizioni_al',
                'La chiusura iscrizioni non può precedere l\'apertura.'
            );
        }
    }

    /**
     * In modifica la capienza non può scendere sotto i posti già occupati
     * da prenotazioni confermate o in attesa di pagamento (posti riservati).
     */
    protected function validaCapienzaNonRiducibile(Validator $validator): void
    {
        $evento = $this->route('evento');

        if (! $evento instanceof Evento) {
            return; // creazione: nessuna prenotazione esistente
        }

        $capienza = $this->input('capienza');

        if ($capienza === null || $capienza === '' || ! ctype_digit((string) $capienza)) {
            return; // illimitata o già segnalata dalla regola integer/min
        }

        $occupati = Prenotazione::postiOccupati($evento->id);

        if ((int) $capienza < $occupati) {
            $validator->errors()->add(
                'capienza',
                "La capienza non può essere ridotta a {$capienza}: ci sono già {$occupati} posti occupati "
                .'da prenotazioni confermate o riservate (in attesa di pagamento). '
                .'Annulla prima le prenotazioni in eccesso.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'titolo' => 'titolo',
            'data_evento' => 'data e ora',
            'data_fine' => 'data fine',
            'luogo' => 'luogo',
            'descrizione' => 'descrizione',
            'stato_pubblicazione' => 'stato pubblicazione',
            'capienza' => 'capienza',
            'prezzo' => 'prezzo',
            'iscrizioni_dal' => 'apertura iscrizioni',
            'iscrizioni_al' => 'chiusura iscrizioni',
            'max_posti_per_prenotazione' => 'posti massimi per prenotazione',
            'lista_attesa_attiva' => 'lista d\'attesa',
            'annullamento_entro_ore' => 'annullamento entro (ore)',
            'immagine_url' => 'immagine (URL)',
        ];
    }
}
