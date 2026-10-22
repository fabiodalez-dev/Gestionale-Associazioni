<?php

namespace App\Rules;

use App\Models\Sede;
use App\Support\PerimetroSedi;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Sede scelta in un form: dell'associazione corrente e, per gli utenti
 * limitati a delle sedi, una delle loro (obbligatoria anche dove per gli
 * altri è facoltativa).
 */
class SedeNelPerimetro implements ValidationRule
{
    public function __construct(private readonly ?string $associazioneId) {}

    /**
     * Regole complete per il campo sede_id.
     *
     * @return list<mixed>
     */
    public static function regole(?string $associazioneId): array
    {
        return [PerimetroSedi::limitato() ? 'required' : 'nullable', 'uuid', new self($associazioneId)];
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $esiste = Sede::query()->withoutGlobalScopes()
            ->where('associazione_id', $this->associazioneId)
            ->whereKey($value)
            ->exists();

        $sedi = PerimetroSedi::sedi();

        if (! $esiste || ($sedi !== null && ! in_array((string) $value, $sedi, true))) {
            $fail('Sede non valida.');
        }
    }
}
