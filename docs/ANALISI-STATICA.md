# Analisi statica

L'analisi statica del progetto si basa su [PHPStan](https://phpstan.org) con l'estensione [Larastan](https://github.com/larastan/larastan) e sul rilevatore di codice morto [shipmonk/dead-code-detector](https://github.com/shipmonk-rnd/dead-code-detector). La configurazione sta in `phpstan.neon`; il risultato atteso è **zero errori**, senza baseline per i nuovi problemi.

## Come si lancia

```bash
composer analyse
```

Equivale a `vendor/bin/phpstan analyse --no-progress --memory-limit=2G`. Per un singolo file: `vendor/bin/phpstan analyse app/Services/Foo.php` (in analisi parziale il rilevatore di codice morto si disattiva da solo, perché ha bisogno dell'intero codice per sapere cosa è usato).

## Cosa copre

- **Livello 5** di PHPStan su `app/`, `routes/`, `database/`, `config/` e `bootstrap/app.php`. Le rotte, le migration e i seeder sono inclusi soprattutto perché usano classi e metodi di `app/` che altrimenti il rilevatore di codice morto considererebbe inutilizzati.
- `checkModelProperties: true` e `parseModelCastsMethod: true`: Larastan verifica gli attributi dei model e legge i cast dichiarati nel metodo `casts()`.
- Nessuna baseline: l'unico falso positivo noto di Larastan (l'array validato `array<string, mixed>` rifiutato da `array<model property, mixed>` in `create()`/`update()`) è una regola mirata in `ignoreErrors` di `phpstan.neon`, limitata a quel messaggio e ad `app/`. La CI (workflow "Qualità del codice") fallisce su qualsiasi altro errore.

## Rilevatore di codice morto

`shipmonk/dead-code-detector` segnala metodi (`shipmonk.deadMethod`), costanti (`shipmonk.deadConstant`) e proprietà mai lette (`shipmonk.deadProperty.neverRead`) che nessun file analizzato referenzia. Quando segnala qualcosa, prima di cancellare va cercato l'uso anche fuori dai path analizzati: viste Blade (`resources/views`), test, stringhe (`'nomeMetodo'`), `defined()`/`constant()`.

Tre esiti possibili:

1. **Davvero morto**: si cancella (metodo, costante o proprietà, e i test che esistevano solo per quello).
2. **Usato solo da una vista Blade** (es. `app(Service::class)->metodo()` in un `@php`): si aggiunge al docblock il tag `@api` con una riga che dice da quale vista è chiamato. Il rilevatore tratta i membri `@api` come punti d'ingresso. Esempi: `CompleanniService::compleanniDelMese`, `ContattiRapidi::whatsappUrl`, le costanti `GRUPPO_*` dei form.
3. **Punto d'ingresso del framework**: rientra in una delle categorie già ignorate in `phpstan.neon` (vedi sotto). Se ne serve una nuova, la regola va limitata a identificatore + percorso (e nome del membro quando possibile) e accompagnata dal motivo.

## Regole di ignore in `phpstan.neon`

Ogni voce di `ignoreErrors` copre una sola categoria di falso positivo strutturale, cioè codice che Laravel invoca per nome, per convenzione o per riflessione:

| Regola | Perché è un punto d'ingresso |
|---|---|
| `__construct` in `app/Services/*` | i servizi sono risolti dal container (`app()`, iniezione), mai con `new` |
| `__construct` in `app/Notifications/*` | istanziate dal pacchetto spatie/laravel-personal-data-export a partire dal nome classe in `config/personal-data-export.php` |
| metodi in `database/factories/*` | gli stati delle factory sono usati dai test, che non sono tra i path analizzati |
| metodi in `app/Policies/*` | risolti per nome da `Gate::authorize('export', ...)`, `$this->authorize()`, `@can`, middleware `can:` |
| metodi in `app/Listeners/*` | `RegistraAccesso::subscribe()` restituisce la mappa evento => nome metodo, registrata con `Event::subscribe()` |
| metodi in `app/Actions/Fortify/*` | registrate per nome classe in `FortifyServiceProvider`, Fortify le chiama tramite il container |
| `boot<Trait>()` in `app/Models/Concerns/*` | `Model::bootTraits()` chiama per convenzione il metodo statico `boot<NomeTrait>()` |
| `SEDE_FACOLTATIVA`, `PERIMETRO_TRAMITE`, `PERIMETRO_FACOLTATIVO` in `app/Models/*` | lette dai trait `NelPerimetroSede*` con `defined(static::class.'::X')` / `constant()` |
| proprietà pubbliche in `app/Mail/*` | passate automaticamente alla vista Blade della Mailable e lette lì |
| `$tries`, `$backoff`, `$uniqueFor`, `$timeout`, `$maxExceptions` in `app/Jobs/*` | lette dal worker della coda e da `ShouldBeUnique` per riflessione |
| proprietà pubbliche in `app/Data/*` | DTO spatie/laravel-data serializzati in JSON per riflessione |

`reportUnmatchedIgnoredErrors` è a `false`: una regola che non trova più nulla da ignorare non fa fallire l'analisi.

## Prossimo passo possibile

Aggiungere `tests/` ai `paths` renderebbe visibili al rilevatore gli stati delle factory e i metodi usati solo dai test, ma oggi i test producono una cinquantina di errori PHPStan di livello 5 da sistemare prima.
