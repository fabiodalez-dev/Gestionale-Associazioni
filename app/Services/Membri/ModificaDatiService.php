<?php

namespace App\Services\Membri;

use App\Models\RichiestaModificaDati;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\Utente;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Propaganistas\LaravelPhone\Rules\Phone;

/**
 * Richieste di modifica dei dati anagrafici (f.19 — piano "Gestione 2").
 *
 * Il socio (area soci) o l'account collegato propone modifiche su una
 * whitelist di campi anagrafici; la proposta salva SOLO i campi davvero
 * diversi dal valore corrente ({campo: nuovo_valore} in json).
 * L'admin vede il diff e approva (applica al socio + storico) o rifiuta
 * con motivo (chiave riservata '_motivo_rifiuto' nel json, la tabella
 * non ha una colonna dedicata).
 */
class ModificaDatiService
{
    /** Chiave riservata nel json proposte per il motivo di rifiuto. */
    public const CHIAVE_MOTIVO_RIFIUTO = '_motivo_rifiuto';

    /** @var list<string> campi anagrafici proponibili dal socio/account */
    public const CAMPI = [
        'nome',
        'cognome',
        'email',
        'telefono',
        'codice_fiscale',
        'data_nascita',
        'indirizzo',
        'citta',
        'provincia',
        'cap',
    ];

    /** @var array<string, string> label italiane dei campi proponibili */
    public const LABELS = [
        'nome' => 'Nome',
        'cognome' => 'Cognome',
        'email' => 'Email',
        'telefono' => 'Telefono',
        'codice_fiscale' => 'Codice fiscale',
        'data_nascita' => 'Data di nascita',
        'indirizzo' => 'Indirizzo',
        'citta' => 'Città',
        'provincia' => 'Provincia',
        'cap' => 'CAP',
    ];

    /**
     * Regole di validazione del form di proposta (tutti i campi opzionali:
     * la richiesta nasce solo dai campi modificati).
     *
     * @return array<string, list<mixed>>
     */
    public function rules(Socio $socio): array
    {
        return [
            'nome' => ['nullable', 'string', 'max:100'],
            'cognome' => ['nullable', 'string', 'max:100'],
            'email' => [
                'nullable', 'email', 'max:255',
                Rule::unique('soci', 'email')
                    ->where('associazione_id', $socio->associazione_id)
                    ->ignore($socio->id),
            ],
            'telefono' => ['nullable', 'string', 'max:20', (new Phone)->country(['IT'])->lenient()],
            'codice_fiscale' => [
                'nullable', 'string', 'max:16', 'codice_fiscale',
                Rule::unique('soci', 'codice_fiscale')
                    ->where('associazione_id', $socio->associazione_id)
                    ->ignore($socio->id),
            ],
            'data_nascita' => ['nullable', 'date', 'before_or_equal:today'],
            'indirizzo' => ['nullable', 'string', 'max:255'],
            'citta' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'size:2', 'alpha'],
            'cap' => ['nullable', 'digits:5'],
        ];
    }

    /**
     * Estrae dal form le sole proposte EFFETTIVE: campi in whitelist con
     * valore non vuoto e diverso dal valore corrente del socio.
     *
     * @param  array<string, mixed>  $validati
     * @return array<string, string> {campo: nuovo_valore}
     */
    public function proposteDaInput(Socio $socio, array $validati): array
    {
        $proposte = [];

        foreach (self::CAMPI as $campo) {
            $valore = $validati[$campo] ?? null;

            if (! is_scalar($valore)) {
                continue;
            }

            $valore = trim((string) $valore);

            if ($valore === '') {
                continue;
            }

            if (in_array($campo, ['codice_fiscale', 'provincia'], true)) {
                $valore = strtoupper($valore);
            }

            if ($valore !== $this->valoreCorrente($socio, $campo)) {
                $proposte[$campo] = $valore;
            }
        }

        return $proposte;
    }

    /**
     * Valore corrente del socio normalizzato a stringa confrontabile.
     */
    public function valoreCorrente(Socio $socio, string $campo): string
    {
        if ($campo === 'data_nascita') {
            return (string) $socio->data_nascita?->toDateString();
        }

        return trim((string) $socio->{$campo});
    }

    /**
     * Il socio ha già una richiesta in attesa di gestione?
     */
    public function haRichiestaPendente(Socio $socio): bool
    {
        return RichiestaModificaDati::withoutGlobalScopes()
            ->where('socio_id', $socio->id)
            ->inAttesa()
            ->exists();
    }

    /**
     * Crea la richiesta in attesa (origine: Socio dell'area soci o
     * Account collegato).
     *
     * @param  array<string, string>  $proposte
     */
    public function proponi(Socio $socio, Model $origine, array $proposte): RichiestaModificaDati
    {
        return RichiestaModificaDati::create([
            'associazione_id' => $socio->associazione_id,
            'socio_id' => $socio->id,
            'proposte' => $proposte,
            'stato' => RichiestaModificaDati::STATO_IN_ATTESA,
            'origine_type' => $origine->getMorphClass(),
            'origine_id' => $origine->getKey(),
        ]);
    }

    /**
     * Approva: applica al socio i soli campi in whitelist e scrive lo
     * storico con il diff applicato.
     */
    public function approva(RichiestaModificaDati $richiesta, Utente $admin): void
    {
        DB::transaction(function () use ($richiesta, $admin): void {
            /** @var Socio $socio */
            $socio = $richiesta->socio()->withoutGlobalScopes()->firstOrFail();

            $applicate = [];

            foreach ($this->proposteVisibili($richiesta) as $campo => $valore) {
                $precedente = $this->valoreCorrente($socio, $campo);
                $socio->{$campo} = $valore;
                $applicate[] = sprintf(
                    '%s: "%s" → "%s"',
                    self::LABELS[$campo] ?? $campo,
                    $precedente !== '' ? $precedente : '—',
                    $valore
                );
            }

            $socio->save();

            StoricoAttivitaSocio::create([
                'associazione_id' => $richiesta->associazione_id,
                'socio_id' => $socio->id,
                'utente_id' => $admin->id,
                'tipo_attivita' => 'Modifica Dati',
                'descrizione' => 'Richiesta di modifica dati approvata. '.implode('; ', $applicate),
                'dettagli_json' => $this->proposteVisibili($richiesta),
                'data_attivita' => now(),
            ]);

            $richiesta->update([
                'stato' => RichiestaModificaDati::STATO_APPROVATA,
                'gestita_da' => $admin->id,
                'gestita_at' => now(),
            ]);
        });
    }

    /**
     * Rifiuta con motivo (storicizzato anche nello storico del socio).
     */
    public function rifiuta(RichiestaModificaDati $richiesta, Utente $admin, string $motivo): void
    {
        DB::transaction(function () use ($richiesta, $admin, $motivo): void {
            StoricoAttivitaSocio::create([
                'associazione_id' => $richiesta->associazione_id,
                'socio_id' => $richiesta->socio_id,
                'utente_id' => $admin->id,
                'tipo_attivita' => 'Modifica Dati',
                'descrizione' => 'Richiesta di modifica dati rifiutata: '.$motivo,
                'data_attivita' => now(),
            ]);

            $richiesta->update([
                'proposte' => array_merge(
                    (array) $richiesta->proposte,
                    [self::CHIAVE_MOTIVO_RIFIUTO => $motivo]
                ),
                'stato' => RichiestaModificaDati::STATO_RIFIUTATA,
                'gestita_da' => $admin->id,
                'gestita_at' => now(),
            ]);
        });
    }

    /**
     * Proposte visualizzabili/applicabili: solo i campi in whitelist
     * (le chiavi riservate che iniziano con '_' vengono escluse).
     *
     * @return array<string, string>
     */
    public function proposteVisibili(RichiestaModificaDati $richiesta): array
    {
        $proposte = [];

        foreach ((array) $richiesta->proposte as $campo => $valore) {
            if (in_array($campo, self::CAMPI, true) && is_scalar($valore)) {
                $proposte[$campo] = (string) $valore;
            }
        }

        return $proposte;
    }

    /**
     * Motivo di rifiuto salvato nel json (NULL se assente).
     */
    public function motivoRifiuto(RichiestaModificaDati $richiesta): ?string
    {
        $motivo = ((array) $richiesta->proposte)[self::CHIAVE_MOTIVO_RIFIUTO] ?? null;

        return is_string($motivo) && $motivo !== '' ? $motivo : null;
    }
}
