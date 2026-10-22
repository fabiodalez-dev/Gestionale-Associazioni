<?php

namespace App\Support;

use Closure;
use Illuminate\Validation\Rules\Password;
use Ziming\LaravelZxcvbn\Rules\ZxcvbnRule;

/**
 * Politica password unica per tutti gli utenti (amministratori, soci, account pubblici).
 *
 * Oltre alla lunghezza minima stima la robustezza reale con zxcvbn
 * (ziming/laravel-zxcvbn): parole comuni, sequenze, date e dati personali
 * dell'utente abbassano il punteggio. La soglia è ZXCVBN_MIN_SCORE (0-4,
 * default 3), modificabile dalle Impostazioni di sistema.
 */
final class PasswordPolicy
{
    public const MIN_LENGTH = 10;

    /** Limite di bcrypt: oltre i 72 byte il resto della password viene ignorato */
    public const MAX_LENGTH = 72;

    /**
     * Regole di robustezza, da combinare con required/nullable/confirmed della singola request.
     *
     * @param  array<int, mixed>  $userInputs  dati dell'utente (nome, cognome, email…) da non usare nella password
     * @return list<mixed>
     */
    public static function rules(array $userInputs = []): array
    {
        $inputs = self::normalizeInputs($userInputs);

        return [
            'string',
            Password::min(self::MIN_LENGTH)->max(self::MAX_LENGTH),
            new ZxcvbnRule($inputs),
            // zxcvbn pesa i dati personali ma non sempre li esclude (es. "MarioRossi2026" ha punteggio 3)
            function (string $attribute, mixed $value, Closure $fail) use ($inputs): void {
                $password = mb_strtolower((string) $value);
                foreach ($inputs as $input) {
                    if (mb_strlen($input) >= 3 && str_contains($password, $input)) {
                        $fail('La password non può contenere il tuo nome, cognome o indirizzo email.');

                        return;
                    }
                }
            },
        ];
    }

    /**
     * Messaggi di validazione per il campo indicato.
     *
     * @return array<string, string>
     */
    public static function messages(string $field = 'password'): array
    {
        return [
            $field.'.min' => 'La password deve contenere almeno '.self::MIN_LENGTH.' caratteri.',
            $field.'.max' => 'La password non può superare i '.self::MAX_LENGTH.' caratteri.',
            $field.'.confirmed' => 'Le due password non coincidono.',
        ];
    }

    /**
     * Suggerimento da mostrare sotto il campo password.
     */
    public static function hint(): string
    {
        return 'Almeno '.self::MIN_LENGTH.' caratteri. Evita parole comuni, sequenze e dati personali: una frase di tre o quattro parole è sicura e facile da ricordare.';
    }

    /**
     * @param  array<int, mixed>  $userInputs
     * @return list<string>
     */
    private static function normalizeInputs(array $userInputs): array
    {
        $inputs = [];
        foreach ($userInputs as $value) {
            if (! is_string($value) || trim($value) === '') {
                continue;
            }
            $value = mb_strtolower(trim($value));
            $inputs[] = $value;
            // Per le email conta soprattutto la parte prima della @
            if (str_contains($value, '@')) {
                $inputs[] = (string) strstr($value, '@', true);
            }
        }

        return array_values(array_unique($inputs));
    }
}
