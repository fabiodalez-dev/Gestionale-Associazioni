<?php

namespace App\Services\Iscrizioni;

use App\Models\Account;
use App\Models\Associazione;
use App\Models\CampoPersonalizzato;
use App\Models\RichiestaIscrizione;
use App\Models\Socio;
use App\Models\StoricoAttivitaSocio;
use App\Models\ValoreCampoPersonalizzato;
use App\Services\Soci\NumeroProgressivoService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Mews\Purifier\Facades\Purifier;

/**
 * Form di iscrizione socio personalizzabile per tenant (PIANO-ISCRIZIONI
 * §Form personalizzabile), schema-driven da associazioni.iscrizione_form_config:
 *
 * - schema():  schema risolto (intro purificata, campi standard normalizzati,
 *              oggetti CampoPersonalizzato del tenant nell'ordine configurato);
 * - rules():   regole di validazione dinamiche (laravel-phone, codice fiscale,
 *              tipi reali dei campi personalizzati);
 * - salva():   transazione: crea il Socio ('In Attesa' o 'Attivo' a seconda
 *              della modalità), salva i valori dei campi personalizzati e la
 *              RichiestaIscrizione con lo snapshot di tutti i dati inviati.
 *
 * Nome, cognome ed email arrivano dall'account loggato (non dal form).
 */
class FormIscrizioneService
{
    /** Campi anagrafici della tabella soci esponibili nel form pubblico. */
    public const CAMPI_STANDARD = [
        'telefono',
        'codice_fiscale',
        'data_nascita',
        'indirizzo',
        'citta',
        'provincia',
        'cap',
    ];

    /**
     * Default sensato quando iscrizione_form_config è assente
     * (stessa struttura dell'esempio nel piano).
     *
     * @var list<array{campo: string, visibile: bool, obbligatorio: bool}>
     */
    public const CAMPI_STANDARD_DEFAULT = [
        ['campo' => 'telefono', 'visibile' => true, 'obbligatorio' => true],
        ['campo' => 'codice_fiscale', 'visibile' => true, 'obbligatorio' => false],
        ['campo' => 'data_nascita', 'visibile' => true, 'obbligatorio' => true],
        ['campo' => 'indirizzo', 'visibile' => false, 'obbligatorio' => false],
        ['campo' => 'citta', 'visibile' => true, 'obbligatorio' => false],
        ['campo' => 'provincia', 'visibile' => false, 'obbligatorio' => false],
        ['campo' => 'cap', 'visibile' => false, 'obbligatorio' => false],
    ];

    /** @var array<string, string> label italiane dei campi standard */
    public const LABELS = [
        'telefono' => 'Telefono',
        'codice_fiscale' => 'Codice fiscale',
        'data_nascita' => 'Data di nascita',
        'indirizzo' => 'Indirizzo',
        'citta' => 'Città',
        'provincia' => 'Provincia',
        'cap' => 'CAP',
    ];

    public function __construct(
        private readonly NumeroProgressivoService $numeriProgressivi,
    ) {}

    /**
     * Schema risolto del form per il tenant.
     *
     * @return array{
     *     intro_html: ?string,
     *     campi_standard: list<array{campo: string, visibile: bool, obbligatorio: bool}>,
     *     campi_personalizzati: Collection<int, CampoPersonalizzato>
     * }
     */
    public function schema(Associazione $associazione): array
    {
        $config = is_array($associazione->iscrizione_form_config)
            ? $associazione->iscrizione_form_config
            : [];

        $introHtml = trim((string) ($config['intro_html'] ?? ''));

        return [
            'intro_html' => $introHtml === '' ? null : Purifier::clean($introHtml),
            'campi_standard' => $this->risolviCampiStandard($config),
            'campi_personalizzati' => $this->risolviCampiPersonalizzati($associazione, $config),
        ];
    }

    /**
     * Regole di validazione dinamiche costruite dallo schema risolto.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(Associazione $associazione): array
    {
        $schema = $this->schema($associazione);
        $rules = [];

        foreach ($schema['campi_standard'] as $riga) {
            if (! $riga['visibile']) {
                continue;
            }

            $rules[$riga['campo']] = array_merge(
                $riga['obbligatorio'] ? ['required'] : ['nullable'],
                $this->regoleCampoStandard($riga['campo'])
            );
        }

        $rules['custom_field'] = ['nullable', 'array'];

        foreach ($schema['campi_personalizzati'] as $campo) {
            // Checkbox mai required (non spuntata = non inviata), come nel legacy
            $obbligatorio = $campo->obbligatorio && $campo->tipo_campo !== 'checkbox';

            $rules['custom_field.'.$campo->id] = array_merge(
                $obbligatorio ? ['required'] : ['nullable'],
                $this->regoleCampoPersonalizzato($campo)
            );
        }

        return $rules;
    }

    /**
     * Salva la richiesta di iscrizione in transazione.
     *
     * Modalità 'preiscrizione': Socio "In Attesa", richiesta in_attesa
     * (l'admin approva/rifiuta). Modalità 'diretta': Socio "Attivo",
     * account collegato subito, richiesta già approvata (gestita_da null).
     *
     * @param  array<string, mixed>  $dati  input validato con rules() (snapshot integrale in dati_form)
     *
     * @throws IscrizioneException
     */
    public function salva(Account $account, array $dati): RichiestaIscrizione
    {
        $associazione = Associazione::query()->findOrFail($account->associazione_id);

        if (! $associazione->iscrizioni_socio_aperte) {
            throw IscrizioneException::iscrizioniChiuse();
        }

        if ($account->socio_id !== null) {
            throw IscrizioneException::giaSocio();
        }

        // Una sola richiesta in_attesa per account (check applicativo da piano)
        $pendente = RichiestaIscrizione::query()
            ->forAssociazione($associazione->id)
            ->where('account_id', $account->id)
            ->inAttesa()
            ->exists();

        if ($pendente) {
            throw IscrizioneException::richiestaGiaInAttesa();
        }

        $schema = $this->schema($associazione);

        return DB::transaction(function () use ($account, $associazione, $dati, $schema): RichiestaIscrizione {
            $diretta = $associazione->isIscrizioneDiretta();

            $socio = Socio::create(array_merge(
                $this->anagraficaDalForm($schema, $dati),
                [
                    'associazione_id' => $associazione->id,
                    'numero_socio' => $this->numeriProgressivi->prossimoNumeroSocio($associazione->id),
                    'nome' => $account->nome,
                    'cognome' => $account->cognome,
                    'email' => $account->email,
                    'data_iscrizione' => now()->toDateString(),
                    'stato' => $diretta ? Socio::STATO_ATTIVO : Socio::STATO_IN_ATTESA,
                    // L'account ha già accettato la privacy alla registrazione
                    'privacy_consenso' => true,
                ]
            ));

            $this->salvaValoriCampiPersonalizzati($schema, $socio, $dati);

            StoricoAttivitaSocio::create([
                'associazione_id' => $associazione->id,
                'socio_id' => $socio->id,
                'tipo_attivita' => 'Iscrizione',
                'descrizione' => $diretta
                    ? 'Iscrizione diretta dal form pubblico del sito.'
                    : 'Richiesta di iscrizione inviata dal form pubblico del sito.',
                'data_attivita' => now(),
            ]);

            $richiesta = RichiestaIscrizione::create([
                'associazione_id' => $associazione->id,
                'account_id' => $account->id,
                'socio_id' => $socio->id,
                'stato' => $diretta
                    ? RichiestaIscrizione::STATO_APPROVATA
                    : RichiestaIscrizione::STATO_IN_ATTESA,
                'dati_form' => $dati,
                'gestita_at' => $diretta ? now() : null,
            ]);

            $account->forceFill(array_merge(
                ['richiesta_socio_at' => now()],
                $diretta ? ['socio_id' => $socio->id] : []
            ))->save();

            return $richiesta;
        });
    }

    // ------------------------------------------------------------------
    // Risoluzione schema
    // ------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $config
     * @return list<array{campo: string, visibile: bool, obbligatorio: bool}>
     */
    private function risolviCampiStandard(array $config): array
    {
        $configurati = $config['campi_standard'] ?? null;

        if (! is_array($configurati) || $configurati === []) {
            $configurati = self::CAMPI_STANDARD_DEFAULT;
        }

        $campi = [];
        $visti = [];

        foreach ($configurati as $riga) {
            $campo = is_array($riga) ? ($riga['campo'] ?? null) : null;

            // Whitelist anti-tamper: solo i campi anagrafici esponibili, senza duplicati
            if (! is_string($campo) || ! in_array($campo, self::CAMPI_STANDARD, true) || isset($visti[$campo])) {
                continue;
            }

            $visti[$campo] = true;
            $campi[] = [
                'campo' => $campo,
                'visibile' => (bool) ($riga['visibile'] ?? false),
                'obbligatorio' => (bool) ($riga['obbligatorio'] ?? false),
            ];
        }

        return $campi;
    }

    /**
     * Oggetti CampoPersonalizzato del tenant nell'ordine configurato
     * (gli id di altri tenant o inesistenti sono scartati).
     *
     * @param  array<string, mixed>  $config
     * @return Collection<int, CampoPersonalizzato>
     */
    private function risolviCampiPersonalizzati(Associazione $associazione, array $config): Collection
    {
        $ids = array_values(array_filter(
            is_array($config['campi_personalizzati'] ?? null) ? $config['campi_personalizzati'] : [],
            'is_string'
        ));

        if ($ids === []) {
            return new Collection;
        }

        return CampoPersonalizzato::query()
            ->forAssociazione($associazione->id)
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (CampoPersonalizzato $campo) => array_search($campo->id, $ids, true))
            ->values()
            ->toBase();
    }

    // ------------------------------------------------------------------
    // Regole di validazione
    // ------------------------------------------------------------------

    /**
     * @return list<mixed>
     */
    private function regoleCampoStandard(string $campo): array
    {
        return match ($campo) {
            'telefono' => ['string', 'max:20', 'phone:INTERNATIONAL,IT'],
            'codice_fiscale' => ['string', 'max:16', 'codice_fiscale'],
            'data_nascita' => ['date', 'before:today'],
            'indirizzo' => ['string', 'max:500'],
            'citta' => ['string', 'max:100'],
            'provincia' => ['string', 'size:2', 'alpha'],
            'cap' => ['digits:5'],
            default => ['string', 'max:255'],
        };
    }

    /**
     * Regole in base al tipo reale del campo (tabella campi_personalizzati),
     * stessa mappa del form soci amministrativo.
     *
     * @return list<mixed>
     */
    private function regoleCampoPersonalizzato(CampoPersonalizzato $campo): array
    {
        return match ($campo->tipo_campo) {
            'email' => ['email', 'max:255'],
            'tel' => ['string', 'max:20'],
            'number' => ['numeric'],
            'date' => ['date'],
            'url' => ['url', 'max:500'],
            'select' => [Rule::in(array_filter(array_map('trim', explode("\n", (string) $campo->opzioni))))],
            'checkbox' => ['in:0,1,on,true'],
            default => ['string', 'max:1000'],
        };
    }

    // ------------------------------------------------------------------
    // Persistenza
    // ------------------------------------------------------------------

    /**
     * Attributi anagrafici del socio dai soli campi standard VISIBILI dello
     * schema (anti-tamper: l'input dei campi nascosti viene ignorato).
     *
     * @param  array{campi_standard: list<array{campo: string, visibile: bool, obbligatorio: bool}>}  $schema
     * @param  array<string, mixed>  $dati
     * @return array<model-property<Socio>, mixed>
     */
    private function anagraficaDalForm(array $schema, array $dati): array
    {
        $anagrafica = [];

        foreach ($schema['campi_standard'] as $riga) {
            if (! $riga['visibile']) {
                continue;
            }

            $campo = $riga['campo'];
            $valore = $dati[$campo] ?? null;

            if (is_string($valore)) {
                $valore = trim($valore);
                $valore = $valore === '' ? null : $valore;
            }

            if (is_string($valore) && in_array($campo, ['codice_fiscale', 'provincia'], true)) {
                $valore = strtoupper($valore);
            }

            $anagrafica[$campo] = $valore;
        }

        return $anagrafica;
    }

    /**
     * Valori dei campi personalizzati configurati nello schema
     * (solo non vuoti, come nella preiscrizione legacy).
     *
     * @param  array{campi_personalizzati: Collection<int, CampoPersonalizzato>}  $schema
     * @param  array<string, mixed>  $dati
     */
    private function salvaValoriCampiPersonalizzati(array $schema, Socio $socio, array $dati): void
    {
        $valori = is_array($dati['custom_field'] ?? null) ? $dati['custom_field'] : [];

        foreach ($schema['campi_personalizzati'] as $campo) {
            $valore = $valori[$campo->id] ?? null;
            $valore = is_scalar($valore) ? trim((string) $valore) : '';

            if ($valore === '') {
                continue;
            }

            ValoreCampoPersonalizzato::create([
                'socio_id' => $socio->id,
                'campo_id' => $campo->id,
                'valore' => $valore,
            ]);
        }
    }
}
