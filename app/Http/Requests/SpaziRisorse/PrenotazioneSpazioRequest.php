<?php

namespace App\Http\Requests\SpaziRisorse;

use App\Models\PrenotazioneSpazio;
use App\Models\Spazio;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Validazione prenotazione interna di uno spazio (f.8 piano "Organizzazione 3").
 *
 * Regola chiave: rilevazione conflitti di overlap sullo stesso spazio
 * (scopeInConflittoCon del modello) — bloccante, con indicazione della
 * prenotazione esistente in conflitto.
 */
class PrenotazioneSpazioRequest extends FormRequest
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
            'spazio_id' => [
                'required',
                'uuid',
                // Solo spazi del tenant corrente (Rule::exists ignora il global scope)
                Rule::exists((new Spazio)->getTable(), 'id')
                    ->where('associazione_id', PrenotazioneSpazio::currentAssociazioneId()),
            ],
            'titolo' => ['required', 'string', 'max:200'],
            'dal' => ['required', 'date'],
            'al' => ['required', 'date', 'after:dal'],
            'note' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Se i campi base sono invalidi, non ha senso cercare conflitti
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->validaConflitti($validator);
        });
    }

    /**
     * Overlap sullo stesso spazio: blocca e indica la prenotazione esistente
     * (in modifica la prenotazione corrente è esclusa dal confronto).
     */
    protected function validaConflitti(Validator $validator): void
    {
        $prenotazione = $this->route('prenotazione');
        $ignoraId = $prenotazione instanceof PrenotazioneSpazio ? $prenotazione->id : null;

        // Normalizzati a Carbon: l'input datetime-local usa "T" mentre il DB
        // memorizza con lo spazio → il confronto deve avvenire su date reali.
        $dal = $this->date('dal');
        $al = $this->date('al');

        if ($dal === null || $al === null) {
            return;
        }

        $conflitto = PrenotazioneSpazio::query()
            ->inConflittoCon(
                (string) $this->input('spazio_id'),
                $dal,
                $al,
                $ignoraId
            )
            ->orderBy('dal')
            ->first();

        if ($conflitto !== null) {
            $validator->errors()->add(
                'dal',
                sprintf(
                    'Lo spazio è già prenotato per "%s" dal %s al %s. Scegli un altro orario.',
                    $conflitto->titolo,
                    $conflitto->dal->format('d/m/Y H:i'),
                    $conflitto->al->format('d/m/Y H:i'),
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
            'spazio_id' => 'spazio',
            'titolo' => 'titolo/uso',
            'dal' => 'inizio',
            'al' => 'fine',
            'note' => 'note',
        ];
    }
}
