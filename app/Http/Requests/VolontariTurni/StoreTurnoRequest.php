<?php

namespace App\Http\Requests\VolontariTurni;

use App\Models\Evento;
use App\Models\Mansione;
use App\Models\Progetto;
use App\Models\Spazio;
use App\Models\Turno;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creazione/modifica turno (f.3 del piano "Organizzazione 3"): titolo,
 * data, fascia oraria, posti, mansione/evento/spazio opzionali, stato e
 * auto_conferma (f.4). Autorizzazione via TurnoPolicy nel controller.
 */
class StoreTurnoRequest extends FormRequest
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
            'titolo' => ['required', 'string', 'max:200'],
            'data' => ['required', 'date'],
            'ora_inizio' => ['required', 'date_format:H:i'],
            'ora_fine' => ['required', 'date_format:H:i', 'after:ora_inizio'],
            'posti' => ['required', 'integer', 'min:1', 'max:999'],
            'mansione_id' => ['nullable', 'uuid'],
            'evento_id' => ['nullable', 'uuid'],
            'spazio_id' => ['nullable', 'uuid'],
            // f.14 piano "Organizzazione 3": progetto collegato al turno (opzionale)
            'progetto_id' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:2000'],
            'stato' => ['nullable', Rule::in(Turno::STATI)],
            'auto_conferma' => ['nullable', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->isNotEmpty()) {
                return;
            }

            // I select sono tenant-scoped dal global scope: id di altri
            // tenant spariscono → errore di validazione (isolamento).
            if ($this->filled('mansione_id')
                && ! Mansione::query()->whereKey($this->input('mansione_id'))->exists()) {
                $v->errors()->add('mansione_id', 'La mansione selezionata non è valida.');
            }

            if ($this->filled('evento_id')
                && ! Evento::query()->whereKey($this->input('evento_id'))->exists()) {
                $v->errors()->add('evento_id', 'L\'evento selezionato non è valido.');
            }

            if ($this->filled('spazio_id')
                && ! Spazio::query()->whereKey($this->input('spazio_id'))->exists()) {
                $v->errors()->add('spazio_id', 'Lo spazio selezionato non è valido.');
            }

            if ($this->filled('progetto_id')
                && ! Progetto::query()->whereKey($this->input('progetto_id'))->exists()) {
                $v->errors()->add('progetto_id', 'Il progetto selezionato non è valido.');
            }
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function datiTurno(): array
    {
        return [
            'titolo' => $this->validated('titolo'),
            'data' => $this->validated('data'),
            'ora_inizio' => $this->validated('ora_inizio'),
            'ora_fine' => $this->validated('ora_fine'),
            'posti' => $this->validated('posti'),
            'mansione_id' => $this->validated('mansione_id'),
            'evento_id' => $this->validated('evento_id'),
            'spazio_id' => $this->validated('spazio_id'),
            'progetto_id' => $this->validated('progetto_id'),
            'note' => $this->validated('note'),
            'stato' => $this->input('stato', Turno::STATO_APERTO),
            'auto_conferma' => $this->boolean('auto_conferma'),
        ];
    }
}
