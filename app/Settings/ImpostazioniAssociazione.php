<?php

namespace App\Settings;

use App\Models\Associazione;
use App\Settings\Tenant\ContestoImpostazioni;
use Illuminate\Support\Facades\DB;
use LogicException;
use ReflectionClass;
use ReflectionProperty;
use Spatie\LaravelSettings\Settings;
use Spatie\LaravelSettings\SettingsMapper;

/**
 * Impostazioni di una singola associazione su spatie/laravel-settings
 * (tabella settings, repository "associazione").
 *
 * Si ottengono solo con ::per($associazione), mai dal container: il tenant è
 * esplicito anche in comandi e job che scorrono più associazioni.
 *
 * Transizione dalle colonne di `associazioni` (stessi nomi delle proprietà):
 * - lettura: valore salvato in settings, altrimenti la colonna;
 * - scrittura: settings e colonna insieme, così query SQL, import e codice
 *   non ancora migrato restano coerenti. Le colonne si rimuovono in una
 *   release successiva, dopo aver materializzato le righe mancanti.
 *
 * Le proprietà devono essere di tipi semplici (niente cast né cifratura) e
 * non si usano i lock di spatie: entrambi sono memorizzati per classe, non
 * per associazione.
 */
abstract class ImpostazioniAssociazione extends Settings
{
    private ?Associazione $associazione = null;

    public static function repository(): ?string
    {
        return 'associazione';
    }

    public static function per(Associazione $associazione): static
    {
        $salvati = ContestoImpostazioni::con(
            (string) $associazione->getKey(),
            fn () => app(SettingsMapper::class)->initialize(static::class)
                ->getRepository()->getPropertiesInGroup(static::group()),
        );

        // Modello caricato con colonne parziali (es. with('associazione:id,nome')):
        // rilegge le colonne mancanti invece di ricadere in silenzio sui default.
        $colonne = $associazione;
        $mancanti = array_diff(static::proprieta(), array_keys($salvati), array_keys($associazione->getAttributes()));
        if ($mancanti !== [] && $associazione->exists) {
            $colonne = $associazione->newQuery()->withoutGlobalScopes()
                ->whereKey($associazione->getKey())->first(static::proprieta()) ?? $associazione;
        }

        $valori = [];
        foreach (static::proprieta() as $nome) {
            $valori[$nome] = array_key_exists($nome, $salvati)
                ? $salvati[$nome]
                : $colonne->getAttribute($nome);
        }

        $impostazioni = new static(static::normalizza($valori));
        $impostazioni->associazione = $associazione;

        return $impostazioni;
    }

    public function save(): self
    {
        $associazione = $this->associazione
            ?? throw new LogicException('Impostazioni senza associazione: ottenerle con '.static::class.'::per().');

        DB::transaction(function () use ($associazione): void {
            ContestoImpostazioni::con((string) $associazione->getKey(), fn () => parent::save());

            $associazione->forceFill($this->toArray())->save();
        });

        $associazione->ricordaImpostazioni($this);

        return $this;
    }

    /**
     * Aggiorna e salva le proprietà indicate (le chiavi estranee sono ignorate).
     *
     * @param  array<string, mixed>  $valori
     */
    public function aggiorna(array $valori): static
    {
        $valori = array_intersect_key($valori, array_flip(static::proprieta()));
        $this->fill(static::normalizza(array_merge($this->toArray(), $valori)));
        $this->save();

        return $this;
    }

    /**
     * Nomi delle proprietà (uguali alle colonne di `associazioni` finché esistono).
     *
     * @return list<string>
     */
    public static function proprieta(): array
    {
        return array_values(array_map(
            fn (ReflectionProperty $p) => $p->getName(),
            array_filter(
                (new ReflectionClass(static::class))->getProperties(ReflectionProperty::IS_PUBLIC),
                fn (ReflectionProperty $p) => ! $p->isStatic(),
            ),
        ));
    }

    /**
     * Valori grezzi (colonna o payload JSON) → tipi delle proprietà.
     *
     * @param  array<string, mixed>  $valori
     * @return array<string, mixed>
     */
    abstract protected static function normalizza(array $valori): array;
}
