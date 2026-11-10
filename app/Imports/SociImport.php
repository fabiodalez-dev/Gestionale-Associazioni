<?php

namespace App\Imports;

use App\Models\Associazione;
use App\Models\Socio;
use App\Services\Soci\NumeroProgressivoService;
use App\Services\Soci\SocioActivityLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import soci da Excel/CSV con validazione riga per riga e report errori
 * (miglioramento 12). Le righe valide vengono importate, quelle invalide
 * vengono saltate e riportate con numero riga e messaggi di errore.
 *
 * Intestazioni riconosciute (heading row, case-insensitive, slug):
 * nome, cognome, email, codice_fiscale, telefono, data_nascita,
 * data_iscrizione, stato, numero_socio, indirizzo, citta, provincia, cap, note.
 */
class SociImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    public int $importati = 0;

    /** Righe di dati lette dal file (#55). */
    public int $righeLette = 0;

    /** Soci già presenti aggiornati dal file (#56). */
    public int $aggiornati = 0;

    /**
     * Intestazioni riconosciute oltre a quelle esatte (#53): come le scrive
     * chi esporta da un altro gestionale o da un foglio fatto a mano, in
     * italiano e in inglese. Le chiavi sono già nella forma "slug" di
     * Laravel Excel (minuscole, senza accenti, spazi e punti → _).
     */
    private const SINONIMI = [
        'nome' => ['nome_socio', 'first_name', 'firstname', 'name', 'given_name'],
        'cognome' => ['surname', 'last_name', 'lastname', 'family_name'],
        'email' => ['e_mail', 'mail', 'email_address', 'indirizzo_email', 'posta_elettronica'],
        'codice_fiscale' => ['cf', 'c_f', 'codicefiscale', 'cod_fiscale', 'tax_code', 'fiscal_code'],
        'telefono' => ['tel', 'cellulare', 'cell', 'mobile', 'phone', 'telephone', 'numero_di_telefono', 'recapito_telefonico'],
        'data_nascita' => ['data_di_nascita', 'nato_il', 'nata_il', 'nascita', 'birth_date', 'birthdate', 'date_of_birth', 'dob'],
        'data_iscrizione' => ['data_di_iscrizione', 'iscritto_il', 'iscrizione', 'membership_date', 'joined'],
        'stato' => ['status'],
        'numero_socio' => ['n_socio', 'numero', 'num_socio', 'matricola', 'member_number', 'tessera_socio'],
        'indirizzo' => ['via', 'residenza', 'address', 'street'],
        'citta' => ['comune', 'citta_di_residenza', 'comune_di_residenza', 'localita', 'city', 'town'],
        'provincia' => ['prov', 'pr', 'province'],
        'cap' => ['codice_postale', 'zip', 'zip_code', 'postal_code', 'postcode'],
        'note' => ['annotazioni', 'notes', 'commenti'],
    ];

    /** @var list<array{riga: int, errori: list<string>}> */
    public array $errori = [];

    public function __construct(
        private readonly Associazione $associazione,
        private readonly NumeroProgressivoService $progressivi,
        private readonly SocioActivityLogger $activityLogger,
    ) {}

    public function collection(Collection $rows): void
    {
        DB::transaction(function () use ($rows): void {
            $prossimoNumero = (int) $this->progressivi->prossimoNumeroSocio($this->associazione->id);

            foreach ($rows as $indice => $row) {
                $this->righeLette++;
                $numeroRiga = $indice + 2; // +1 heading row, +1 base 1
                $dati = $this->normalizza($row->toArray());

                $validator = Validator::make($dati, $this->regole($this->socioEsistente($dati)?->id), [], [
                    'nome' => 'Nome',
                    'cognome' => 'Cognome',
                    'email' => 'Email',
                    'codice_fiscale' => 'Codice Fiscale',
                ]);

                if ($validator->fails()) {
                    $this->errori[] = [
                        'riga' => $numeroRiga,
                        'errori' => $validator->errors()->all(),
                    ];

                    continue;
                }

                $valido = $validator->validated();

                // Già presente (#56): stesso codice fiscale, oppure stessa email o
                // numero socio con lo stesso nome e cognome. Si aggiornano solo i
                // campi presenti nel file, mai svuotati; nome e cognome mai.
                if (($esistente = $this->socioEsistente($dati)) !== null) {
                    $esistente->fill(array_filter(
                        collect($valido)->only(array_keys($dati))->except(['privacy_consenso', 'numero_socio', 'data_iscrizione', 'stato', 'nome', 'cognome'])->all(),
                        fn ($v) => $v !== null && $v !== ''
                    ));
                    if ($esistente->isDirty()) {
                        $esistente->save();
                        $this->activityLogger->log($esistente, SocioActivityLogger::TIPO_MODIFICA_ANAGRAFICA, 'Dati aggiornati da import file');
                    }
                    $this->aggiornati++;

                    continue;
                }

                if (blank($valido['numero_socio'] ?? null)) {
                    $valido['numero_socio'] = str_pad((string) $prossimoNumero, 3, '0', STR_PAD_LEFT);
                    $prossimoNumero++;
                }

                $socio = new Socio($valido + [
                    'data_iscrizione' => $valido['data_iscrizione'] ?? now()->toDateString(),
                    'stato' => $valido['stato'] ?? Socio::STATO_ATTIVO,
                ]);
                $socio->associazione_id = $this->associazione->id;
                $socio->save();

                $this->activityLogger->log(
                    $socio,
                    SocioActivityLogger::TIPO_ISCRIZIONE,
                    "Socio importato da file con n. {$socio->numero_socio}"
                );

                $this->importati++;
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalizza(array $row): array
    {
        $row = $this->conSinonimi($row);
        $cf = strtoupper(trim((string) ($row['codice_fiscale'] ?? '')));

        return [
            'nome' => trim((string) ($row['nome'] ?? '')),
            'cognome' => trim((string) ($row['cognome'] ?? '')),
            'email' => strtolower(trim((string) ($row['email'] ?? ''))),
            'codice_fiscale' => $cf === '' ? null : $cf,
            'telefono' => trim((string) ($row['telefono'] ?? '')) ?: null,
            'data_nascita' => $this->parseData($row['data_nascita'] ?? null),
            'data_iscrizione' => $this->parseData($row['data_iscrizione'] ?? null) ?? now()->toDateString(),
            'stato' => trim((string) ($row['stato'] ?? '')) ?: Socio::STATO_ATTIVO,
            'numero_socio' => trim((string) ($row['numero_socio'] ?? '')) ?: null,
            'indirizzo' => trim((string) ($row['indirizzo'] ?? '')) ?: null,
            'citta' => trim((string) ($row['citta'] ?? '')) ?: null,
            'provincia' => strtoupper(trim((string) ($row['provincia'] ?? ''))) ?: null,
            'cap' => trim((string) ($row['cap'] ?? '')) ?: null,
            'note' => trim((string) ($row['note'] ?? '')) ?: null,
            'privacy_consenso' => false,
        ];
    }

    /**
     * Rinomina le colonne riconosciute per sinonimo (#53); a parità vince
     * l'intestazione esatta.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function conSinonimi(array $row): array
    {
        foreach (self::SINONIMI as $campo => $sinonimi) {
            if (filled($row[$campo] ?? null)) {
                continue;
            }
            foreach ($sinonimi as $sinonimo) {
                if (filled($row[$sinonimo] ?? null)) {
                    $row[$campo] = $row[$sinonimo];
                    break;
                }
            }
        }

        return $row;
    }

    /**
     * Socio dell'associazione già presente con lo stesso codice fiscale,
     * email o numero socio, in quest'ordine (#56).
     *
     * @param  array<string, mixed>  $dati
     */
    private function socioEsistente(array $dati): ?Socio
    {
        $base = fn () => Socio::query()->withoutGlobalScopes()->where('associazione_id', $this->associazione->id)->whereNull('deleted_at');

        // Il codice fiscale identifica la persona
        if (filled($dati['codice_fiscale'] ?? null) && ($trovato = $base()->where('codice_fiscale', $dati['codice_fiscale'])->first()) !== null) {
            return $trovato;
        }

        // Email e numero socio solo se anche nome e cognome coincidono: una
        // persona diversa con l'email di un altro socio resta un errore della
        // riga, non un aggiornamento dell'anagrafica sbagliata
        $stessaPersona = fn (Socio $socio) => mb_strtolower(trim((string) $socio->nome)) === mb_strtolower((string) ($dati['nome'] ?? ''))
            && mb_strtolower(trim((string) $socio->cognome)) === mb_strtolower((string) ($dati['cognome'] ?? ''));

        foreach (['email', 'numero_socio'] as $chiave) {
            if (filled($dati[$chiave] ?? null)) {
                $trovato = $chiave === 'email'
                    ? $base()->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $dati[$chiave])])->first()
                    : $base()->where($chiave, $dati[$chiave])->first();
                if ($trovato !== null && $stessaPersona($trovato)) {
                    return $trovato;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function regole(?string $ignoraSocioId = null): array
    {
        $assocId = $this->associazione->id;
        $unico = fn (string $colonna) => Rule::unique('soci', $colonna)->where('associazione_id', $assocId)->ignore($ignoraSocioId);

        return [
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'email', 'max:255',
                $unico('email'),
            ],
            'codice_fiscale' => [
                'nullable', 'string', 'max:16', 'codice_fiscale',
                $unico('codice_fiscale'),
            ],
            'telefono' => ['nullable', 'string', 'max:20'],
            'data_nascita' => ['nullable', 'date'],
            'data_iscrizione' => ['nullable', 'date'],
            'stato' => ['nullable', Rule::in(Socio::STATI)],
            'numero_socio' => [
                'nullable', 'string', 'max:50',
                $unico('numero_socio'),
            ],
            'indirizzo' => ['nullable', 'string', 'max:255'],
            'citta' => ['nullable', 'string', 'max:100'],
            'provincia' => ['nullable', 'string', 'size:2'],
            'cap' => ['nullable', 'string', 'max:5'],
            'note' => ['nullable', 'string'],
            'privacy_consenso' => ['boolean'],
        ];
    }

    private function parseData(mixed $valore): ?string
    {
        if (blank($valore)) {
            return null;
        }

        try {
            if (is_numeric($valore)) {
                return ExcelDate::excelToDateTimeObject((float) $valore)->format('Y-m-d');
            }

            $valore = trim((string) $valore);

            if (preg_match('#^\d{1,2}/\d{1,2}/\d{4}$#', $valore)) {
                return Carbon::createFromFormat('d/m/Y', $valore)->format('Y-m-d');
            }

            return Carbon::parse($valore)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
