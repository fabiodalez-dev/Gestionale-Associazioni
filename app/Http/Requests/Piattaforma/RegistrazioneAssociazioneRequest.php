<?php

namespace App\Http\Requests\Piattaforma;

use App\Models\RichiestaRegistrazione;
use App\Support\PasswordPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Modulo pubblico di iscrizione di un'associazione alla piattaforma.
 */
class RegistrazioneAssociazioneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : null,
            'admin_email' => is_string($this->input('admin_email')) ? mb_strtolower(trim($this->input('admin_email'))) : null,
            'codice_fiscale' => is_string($this->input('codice_fiscale')) ? mb_strtoupper(trim($this->input('codice_fiscale'))) : null,
            'provincia' => is_string($this->input('provincia')) ? mb_strtoupper(trim($this->input('provincia'))) : null,
        ], fn ($v) => $v !== null));
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // Blocca un secondo invio solo se la prima richiesta è stata confermata via email:
        // richieste mai confermate non devono poter "occupare" l'email di qualcun altro
        $inAttesa = fn (string $colonna) => Rule::unique('richieste_registrazione', $colonna)
            ->where('stato', RichiestaRegistrazione::STATO_IN_ATTESA)
            ->whereNotNull('email_verificata_at');

        return [
            'nome' => ['required', 'string', 'max:255'],
            'tipo' => ['nullable', Rule::in(array_keys(RichiestaRegistrazione::TIPI))],
            'email' => ['required', 'email', 'max:255', Rule::unique('associazioni', 'email'), $inAttesa('email')],
            'telefono' => ['nullable', 'string', 'max:20'],
            'codice_fiscale' => ['nullable', 'string', 'regex:/^([A-Z0-9]{11}|[A-Z0-9]{16})$/', Rule::unique('associazioni', 'codice_fiscale')],
            'partita_iva' => ['nullable', 'digits:11', Rule::unique('associazioni', 'partita_iva')],
            'citta' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'size:2', 'alpha'],
            'numero_soci_stimato' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'messaggio' => ['nullable', 'string', 'max:2000'],
            'admin_nome' => ['required', 'string', 'max:100'],
            'admin_cognome' => ['nullable', 'string', 'max:100'],
            'admin_email' => ['required', 'email', 'max:255', Rule::unique('utenti', 'email'), $inAttesa('admin_email')],
            'admin_password' => ['required', ...PasswordPolicy::rules([$this->input('admin_email'), $this->input('admin_nome'), $this->input('admin_cognome'), $this->input('nome')])],
            'privacy' => ['accepted'],
        ];
    }

    /**
     * Al massimo poche email di conferma verso lo stesso indirizzo: il modulo
     * non deve diventare un modo per inondare la casella di qualcun altro.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $email = $this->input('admin_email');
                if ($validator->errors()->isNotEmpty() || ! is_string($email)) {
                    return;
                }
                if (RateLimiter::tooManyAttempts(self::chiaveLimite($email), self::INVII_PER_INDIRIZZO)) {
                    $validator->errors()->add('admin_email', 'Abbiamo già inviato più link di conferma a questo indirizzo: controlla la posta, anche nello spam, oppure riprova domani.');
                }
            },
        ];
    }

    public const INVII_PER_INDIRIZZO = 3;

    public static function chiaveLimite(string $email): string
    {
        return 'registrazione-verifica:'.sha1(mb_strtolower(trim($email)));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Questa email è già usata da un\'associazione iscritta o in attesa di approvazione.',
            'admin_email.unique' => 'Esiste già un account con questa email: accedi oppure usa un\'altra email.',
            'codice_fiscale.unique' => 'Un\'associazione con questo codice fiscale è già iscritta.',
            'codice_fiscale.regex' => 'Il codice fiscale deve avere 11 cifre (enti) o 16 caratteri.',
            'partita_iva.unique' => 'Un\'associazione con questa partita IVA è già iscritta.',
            'privacy.accepted' => 'Serve il consenso al trattamento dei dati per inviare la richiesta.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'nome' => 'nome dell\'associazione',
            'email' => 'email dell\'associazione',
            'admin_nome' => 'nome',
            'admin_cognome' => 'cognome',
            'admin_email' => 'la tua email',
            'admin_password' => 'password',
            'numero_soci_stimato' => 'numero di soci',
        ];
    }
}
