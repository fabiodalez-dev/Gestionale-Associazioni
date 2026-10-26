<?php

namespace App\Http\Requests\Struttura;

use App\Models\Riunione;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creazione/modifica riunione (f.17): organo, titolo, data/ora, luogo o
 * spazio censito, ordine del giorno strutturato [{punto, descrizione,
 * maggioranza}] e stato. L'OdG arriva come array di righe dal form;
 * le righe senza testo vengono scartate in prepareForValidation.
 * Per le assemblee (issue #6) anche seconda convocazione, quorum e
 * requisiti degli aventi diritto; deleghe ammesse (issue #7).
 */
class SalvaRiunioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        $riunione = $this->route('riunione');

        if ($riunione instanceof Riunione) {
            return $this->user()?->can('update', $riunione) ?? false;
        }

        return $this->user()?->can('create', Riunione::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->input('spazio_id') === '') {
            $this->merge(['spazio_id' => null]);
        }

        foreach (['seconda_convocazione_at', 'quorum_prima', 'quorum_seconda'] as $campo) {
            if ($this->input($campo) === '') {
                $this->merge([$campo => null]);
            }
        }

        if (! $this->filled('organo')) {
            $this->merge(['organo' => Riunione::ORGANO_DIRETTIVO]);
        }

        $this->merge([
            'requisito_quota_pagata' => $this->boolean('requisito_quota_pagata'),
            'escludi_minorenni_voto' => $this->has('escludi_minorenni_voto')
                ? $this->boolean('escludi_minorenni_voto')
                : true,
            'deleghe_vietate_cariche' => $this->boolean('deleghe_vietate_cariche'),
        ]);

        if (! $this->filled('max_deleghe_per_socio')) {
            $this->merge(['max_deleghe_per_socio' => 1]);
        }

        // Normalizza l'OdG: scarta le righe senza "punto" e riallinea gli indici
        $odg = $this->input('odg', []);

        if (is_array($odg)) {
            $puliti = [];

            foreach ($odg as $riga) {
                if (! is_array($riga)) {
                    continue;
                }

                $punto = trim((string) ($riga['punto'] ?? ''));

                if ($punto === '') {
                    continue;
                }

                $descrizione = trim((string) ($riga['descrizione'] ?? ''));
                $maggioranza = (string) ($riga['maggioranza'] ?? '');

                $pulito = [
                    'punto' => $punto,
                    'descrizione' => $descrizione !== '' ? $descrizione : null,
                ];

                // La maggioranza si salva solo se diversa dalla semplice
                if ($maggioranza !== '' && $maggioranza !== 'semplice') {
                    $pulito['maggioranza'] = $maggioranza;
                }

                $puliti[] = $pulito;
            }

            $this->merge(['odg' => $puliti]);
        }
    }

    public function rules(): array
    {
        return [
            'titolo' => ['required', 'string', 'max:200'],
            'organo' => ['required', Rule::in(array_keys(Riunione::ORGANI))],
            'data_ora' => ['required', 'date'],
            'seconda_convocazione_at' => ['nullable', 'date', 'after:data_ora'],
            'quorum_prima' => ['nullable', Rule::in(array_keys(Riunione::QUORUM))],
            'quorum_seconda' => ['nullable', Rule::in(array_keys(Riunione::QUORUM))],
            'requisito_quota_pagata' => ['boolean'],
            'escludi_minorenni_voto' => ['boolean'],
            'max_deleghe_per_socio' => ['integer', 'min:0', 'max:20'],
            'deleghe_vietate_cariche' => ['boolean'],
            'luogo' => ['nullable', 'string', 'max:200'],
            'spazio_id' => [
                'nullable',
                'uuid',
                Rule::exists('spazi', 'id')->where('associazione_id', session('associazione_id')),
            ],
            'stato' => ['required', Rule::in(Riunione::STATI)],
            'odg' => ['nullable', 'array'],
            'odg.*.punto' => ['required', 'string', 'max:200'],
            'odg.*.descrizione' => ['nullable', 'string', 'max:1000'],
            'odg.*.maggioranza' => ['nullable', Rule::in(array_keys(Riunione::MAGGIORANZE))],
        ];
    }

    public function messages(): array
    {
        return [
            'spazio_id.exists' => 'Lo spazio selezionato non appartiene all\'associazione corrente.',
            'odg.*.punto.required' => 'Ogni punto dell\'ordine del giorno deve avere un titolo.',
            'seconda_convocazione_at.after' => 'La seconda convocazione deve essere successiva alla prima.',
        ];
    }
}
