<?php

namespace App\Http\Requests\SpaziRisorse;

use App\Models\Attrezzatura;
use App\Models\PrestitoAttrezzatura;
use App\Models\Socio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validazione registrazione di un prestito (f.10 piano "Organizzazione 3").
 *
 * Regola chiave: la quantità richiesta non può superare la disponibilità
 * calcolata dell'attrezzatura (quantita - prestiti attivi) → blocco.
 */
class PrestitoAttrezzaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'quantita' => $this->filled('quantita') ? $this->input('quantita') : 1,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'attrezzatura_id' => [
                'required',
                'uuid',
                Rule::exists((new Attrezzatura)->getTable(), 'id')
                    ->where('associazione_id', PrestitoAttrezzatura::currentAssociazioneId()),
            ],
            'socio_id' => [
                'required',
                'uuid',
                Rule::exists((new Socio)->getTable(), 'id')
                    ->where('associazione_id', PrestitoAttrezzatura::currentAssociazioneId()),
            ],
            'quantita' => ['required', 'integer', 'min:1', 'max:4294967295'],
            'dal' => ['required', 'date'],
            'restituzione_prevista' => ['required', 'date', 'after_or_equal:dal'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validaDisponibilita($validator);
        });
    }

    /**
     * Blocca il prestito se la quantità richiesta supera la disponibilità
     * dell'attrezzatura (quantita totale - pezzi già in prestito attivo).
     */
    protected function validaDisponibilita(Validator $validator): void
    {
        /** @var Attrezzatura|null $attrezzatura */
        $attrezzatura = Attrezzatura::query()->find($this->input('attrezzatura_id'));

        if ($attrezzatura === null) {
            return; // già segnalato da exists
        }

        $disponibile = $attrezzatura->quantitaDisponibile();
        $richiesta = (int) $this->input('quantita');

        if ($richiesta > $disponibile) {
            $validator->errors()->add(
                'quantita',
                sprintf(
                    'Disponibilità insufficiente: richiesti %d pezzi ma solo %d disponibili (su %d totali).',
                    $richiesta,
                    $disponibile,
                    (int) $attrezzatura->quantita,
                )
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'attrezzatura_id' => 'attrezzatura',
            'socio_id' => 'socio',
            'quantita' => 'quantità',
            'dal' => 'data prestito',
            'restituzione_prevista' => 'restituzione prevista',
            'note' => 'note',
        ];
    }
}
