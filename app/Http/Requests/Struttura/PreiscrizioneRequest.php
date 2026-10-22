<?php

namespace App\Http\Requests\Struttura;

use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Services\Moduli\RegistroConsensi;
use App\Services\Struttura\RequiredFieldsService;
use App\Settings\ModuliSettings;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Form pubblico di pre-iscrizione/rinnovo (spec struttura §6.5).
 * Le regole dei campi configurabili sono costruite dinamicamente dalla
 * configurazione campi_obbligatori_config del tenant.
 */
class PreiscrizioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // pagina pubblica: l'accesso è validato dal controller (assoc attiva)
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $associazione = $this->associazione();
        $config = app(RequiredFieldsService::class)->load($associazione);
        $required = fn (string $campo): string => ($config['preiscrizione'][$campo] ?? false) ? 'required' : 'nullable';

        $rules = [
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            // Sempre obbligatorio nel form pubblico (GDPR)
            'privacy_consenso' => ['accepted'],
            'data_nascita' => [$required('data_nascita'), 'date_format:Y-m-d'],
            'telefono' => [$required('telefono'), 'string', 'max:20'],
            'codice_fiscale' => [$required('codice_fiscale'), 'string', 'max:16'],
            'indirizzo' => [$required('indirizzo'), 'string', 'max:500'],
            'citta' => [$required('citta'), 'string', 'max:100'],
            'provincia' => [$required('provincia'), 'string', 'size:2', 'alpha'],
            'cap' => [$required('cap'), 'digits:5'],
            'tipo_socio_id' => [$required('tipo_socio_id'), 'uuid'],
            'categoria_socio_id' => [$required('categoria_socio_id'), 'uuid'],
            'renewal_token' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
            'custom_field' => ['nullable', 'array'],
        ];

        // Campi nascosti dall'associazione: esclusi (mai obbligatori né salvati)
        $moduli = $associazione->moduli();
        foreach (ModuliSettings::CAMPI_NASCONDIBILI as $campo) {
            if (! $moduli->campoVisibile($campo)) {
                $rules[$campo] = ['exclude'];
            }
        }

        // Consensi aggiuntivi configurati dall'associazione
        $rules += app(RegistroConsensi::class)->regole($associazione);

        // Campi personalizzati mostrati e con obbligatorio_preiscrizione=1 (eccetto checkbox)
        $scelti = $moduli->campi_personalizzati_preiscrizione;
        $campi = CampoPersonalizzato::query()
            ->forAssociazione($associazione->id)
            ->where('obbligatorio_preiscrizione', true)
            ->where('tipo_campo', '<>', 'checkbox')
            ->when($scelti !== null, fn ($q) => $q->whereIn('id', $scelti))
            ->get();

        foreach ($campi as $campo) {
            $rules['custom_field.'.$campo->id] = ['required'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = [
            'nome.required' => 'Il nome è obbligatorio.',
            'cognome.required' => 'Il cognome è obbligatorio.',
            'email.required' => 'L\'email è obbligatoria.',
            'email.email' => 'Inserisci un indirizzo email valido.',
            'privacy_consenso.accepted' => 'Devi accettare l\'informativa sulla privacy.',
            'data_nascita.date_format' => 'La data di nascita deve essere nel formato AAAA-MM-GG.',
            'provincia.size' => 'La provincia deve essere di 2 lettere.',
            'provincia.alpha' => 'La provincia deve essere di 2 lettere.',
            'cap.digits' => 'Il CAP deve essere di 5 cifre.',
        ];

        foreach (RequiredFieldsService::LABELS as $campo => $label) {
            $messages[$campo.'.required'] = 'Campo obbligatorio mancante: '.$label.'.';
        }

        $messages['custom_field.*.required'] = 'Compila tutti i campi aggiuntivi obbligatori.';
        $messages += app(RegistroConsensi::class)->messaggi($this->associazione());

        return $messages;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return RequiredFieldsService::LABELS;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        // Normalizzazioni legacy: CF uppercase/NULL, select vuote → NULL
        $cf = trim((string) $this->input('codice_fiscale', ''));
        $merge['codice_fiscale'] = $cf === '' ? null : strtoupper($cf);

        foreach (['tipo_socio_id', 'categoria_socio_id', 'data_nascita', 'provincia', 'cap', 'telefono', 'indirizzo', 'citta'] as $campo) {
            if ($this->input($campo) === '') {
                $merge[$campo] = null;
            }
        }

        if (($merge['provincia'] ?? $this->input('provincia')) !== null && $this->filled('provincia')) {
            $merge['provincia'] = strtoupper(trim((string) $this->input('provincia')));
        }

        $this->merge($merge);
    }

    public function associazione(): Associazione
    {
        $associazione = $this->attributes->get('associazione');

        if (! $associazione instanceof Associazione) {
            // Risolta (e validata: attiva) dal binding del controller pubblico
            $associazione = Associazione::query()
                ->where('id', (string) $this->route('associazione'))
                ->where('attiva', true)
                ->firstOrFail();

            $this->attributes->set('associazione', $associazione);
        }

        return $associazione;
    }
}
