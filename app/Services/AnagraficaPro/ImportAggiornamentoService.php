<?php

namespace App\Services\AnagraficaPro;

use App\Imports\AnagraficaPro\RigheAggiornamentoImport;
use App\Models\Associazione;
use App\Models\Socio;
use App\Services\Soci\SocioActivityLogger;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Import Excel di AGGIORNAMENTO soci (f.25 PIANO-VITA-SOCIALE):
 * match per numero socio o email, calcolo del diff campo per campo
 * (anteprima) e applicazione delle sole modifiche confermate.
 *
 * Intestazioni riconosciute (heading row, slug): numero_socio, email,
 * nome, cognome, telefono, codice_fiscale, data_nascita, indirizzo,
 * citta, provincia, cap, stato, note. Le celle VUOTE vengono ignorate
 * (mai svuotare un campo esistente).
 */
class ImportAggiornamentoService
{
    /** Campi aggiornabili dal file (etichette per l'anteprima diff). */
    public const CAMPI = [
        'nome' => 'Nome',
        'cognome' => 'Cognome',
        'email' => 'Email',
        'telefono' => 'Telefono',
        'codice_fiscale' => 'Codice Fiscale',
        'data_nascita' => 'Data di Nascita',
        'indirizzo' => 'Indirizzo',
        'citta' => 'Città',
        'provincia' => 'Provincia',
        'cap' => 'CAP',
        'stato' => 'Stato',
        'note' => 'Note',
    ];

    public function __construct(private readonly SocioActivityLogger $activityLogger) {}

    /**
     * Anteprima diff: nessuna scrittura sul database.
     *
     * @return array{
     *     modifiche: list<array{riga: int, socio_id: string, numero_socio: ?string, nome_completo: string, match: string, campi: array<string, array{da: mixed, a: mixed}>}>,
     *     invariati: int,
     *     non_trovati: list<array{riga: int, identificativo: string}>,
     *     errori: list<array{riga: int, errori: list<string>}>
     * }
     */
    public function anteprima(Associazione $associazione, string $percorsoAssoluto): array
    {
        $righe = $this->leggiRighe($percorsoAssoluto);

        $modifiche = [];
        $nonTrovati = [];
        $errori = [];
        $invariati = 0;

        foreach ($righe as $indice => $riga) {
            $numeroRiga = $indice + 2; // +1 heading row, +1 base 1
            $dati = $this->normalizza($riga);

            if (blank($dati['numero_socio']) && blank($dati['email'])) {
                $errori[] = ['riga' => $numeroRiga, 'errori' => ['Riga senza numero socio né email: impossibile abbinarla a un socio.']];

                continue;
            }

            [$socio, $match] = $this->trovaSocio($associazione, $dati);

            if ($socio === null) {
                $nonTrovati[] = [
                    'riga' => $numeroRiga,
                    'identificativo' => $dati['numero_socio'] ?: $dati['email'],
                ];

                continue;
            }

            $campiValidi = $this->valida($associazione, $socio, $dati, $numeroRiga, $errori);

            if ($campiValidi === null) {
                continue;
            }

            $diff = $this->diff($socio, $campiValidi);

            if ($diff === []) {
                $invariati++;

                continue;
            }

            $modifiche[] = [
                'riga' => $numeroRiga,
                'socio_id' => $socio->id,
                'numero_socio' => $socio->numero_socio,
                'nome_completo' => $socio->nome_completo,
                'match' => $match,
                'campi' => $diff,
            ];
        }

        return [
            'modifiche' => $modifiche,
            'invariati' => $invariati,
            'non_trovati' => $nonTrovati,
            'errori' => $errori,
        ];
    }

    /**
     * Applica le modifiche calcolate dall'anteprima (il file viene riletto:
     * il diff è deterministico a parità di file e stato del database).
     *
     * @return array{aggiornati: int, campi_aggiornati: int, invariati: int, non_trovati: int, errori: int}
     */
    public function applica(Associazione $associazione, string $percorsoAssoluto): array
    {
        $anteprima = $this->anteprima($associazione, $percorsoAssoluto);
        $campiAggiornati = 0;

        DB::transaction(function () use ($anteprima, &$campiAggiornati): void {
            foreach ($anteprima['modifiche'] as $modifica) {
                /** @var Socio|null $socio */
                $socio = Socio::query()->withoutGlobalScopes()->find($modifica['socio_id']);

                if ($socio === null) {
                    continue;
                }

                foreach ($modifica['campi'] as $campo => $valori) {
                    $socio->{$campo} = $valori['a'];
                    $campiAggiornati++;
                }

                $socio->save();

                $this->activityLogger->log(
                    $socio,
                    SocioActivityLogger::TIPO_MODIFICA_ANAGRAFICA,
                    'Anagrafica aggiornata da import Excel (riga '.$modifica['riga'].')',
                    ['campi' => array_keys($modifica['campi'])]
                );
            }
        });

        return [
            'aggiornati' => count($anteprima['modifiche']),
            'campi_aggiornati' => $campiAggiornati,
            'invariati' => $anteprima['invariati'],
            'non_trovati' => count($anteprima['non_trovati']),
            'errori' => count($anteprima['errori']),
        ];
    }

    /**
     * @return Collection<int, Collection<string, mixed>>
     */
    private function leggiRighe(string $percorsoAssoluto): Collection
    {
        $fogli = Excel::toCollection(new RigheAggiornamentoImport, $percorsoAssoluto);

        return $fogli->first() ?? collect();
    }

    /**
     * @return array{0: ?Socio, 1: string}
     */
    private function trovaSocio(Associazione $associazione, array $dati): array
    {
        if (filled($dati['numero_socio'])) {
            $socio = Socio::forAssociazione($associazione->id)
                ->where('numero_socio', $dati['numero_socio'])
                ->first();

            if ($socio !== null) {
                return [$socio, 'numero_socio'];
            }
        }

        if (filled($dati['email'])) {
            $socio = Socio::forAssociazione($associazione->id)
                ->whereRaw('LOWER(email) = ?', [$dati['email']])
                ->first();

            if ($socio !== null) {
                return [$socio, 'email'];
            }
        }

        return [null, ''];
    }

    /**
     * Valida i soli campi PRESENTI nella riga; ritorna NULL (e accoda
     * l'errore) se la riga non è valida.
     *
     * @param  list<array{riga: int, errori: list<string>}>  $errori
     * @return array<string, mixed>|null
     */
    private function valida(Associazione $associazione, Socio $socio, array $dati, int $numeroRiga, array &$errori): ?array
    {
        // Solo i campi valorizzati nel file: le celle vuote non toccano nulla
        $presenti = array_filter(
            array_intersect_key($dati, self::CAMPI),
            fn ($valore) => $valore !== null && $valore !== ''
        );

        if ($presenti === []) {
            return [];
        }

        $validator = Validator::make($presenti, [
            'nome' => ['sometimes', 'string', 'max:100'],
            'cognome' => ['sometimes', 'string', 'max:100'],
            'email' => [
                'sometimes', 'email', 'max:255',
                Rule::unique('soci', 'email')->where('associazione_id', $associazione->id)->ignore($socio->id),
            ],
            'telefono' => ['sometimes', 'string', 'max:20'],
            'codice_fiscale' => [
                'sometimes', 'string', 'max:16',
                Rule::unique('soci', 'codice_fiscale')->where('associazione_id', $associazione->id)->ignore($socio->id),
            ],
            'data_nascita' => ['sometimes', 'date'],
            'indirizzo' => ['sometimes', 'string', 'max:255'],
            'citta' => ['sometimes', 'string', 'max:100'],
            'provincia' => ['sometimes', 'string', 'size:2'],
            'cap' => ['sometimes', 'string', 'max:5'],
            'stato' => ['sometimes', Rule::in(Socio::STATI)],
            'note' => ['sometimes', 'string'],
        ]);

        if ($validator->fails()) {
            $errori[] = ['riga' => $numeroRiga, 'errori' => array_values($validator->errors()->all())];

            return null;
        }

        return $validator->validated();
    }

    /**
     * @param  array<string, mixed>  $campiValidi
     * @return array<string, array{da: mixed, a: mixed}>
     */
    private function diff(Socio $socio, array $campiValidi): array
    {
        $diff = [];

        foreach ($campiValidi as $campo => $nuovo) {
            $corrente = $campo === 'data_nascita'
                ? $socio->data_nascita?->toDateString()
                : $socio->{$campo};

            if ((string) $corrente === (string) $nuovo) {
                continue;
            }

            $diff[$campo] = ['da' => $corrente, 'a' => $nuovo];
        }

        return $diff;
    }

    /**
     * @param  Collection<string, mixed>|array<string, mixed>  $riga
     * @return array<string, mixed>
     */
    private function normalizza($riga): array
    {
        $row = collect($riga)->toArray();
        $cf = strtoupper(trim((string) ($row['codice_fiscale'] ?? '')));

        return [
            'numero_socio' => trim((string) ($row['numero_socio'] ?? '')) ?: null,
            'nome' => trim((string) ($row['nome'] ?? '')) ?: null,
            'cognome' => trim((string) ($row['cognome'] ?? '')) ?: null,
            'email' => strtolower(trim((string) ($row['email'] ?? ''))) ?: null,
            'telefono' => trim((string) ($row['telefono'] ?? '')) ?: null,
            'codice_fiscale' => $cf === '' ? null : $cf,
            'data_nascita' => $this->parseData($row['data_nascita'] ?? null),
            'indirizzo' => trim((string) ($row['indirizzo'] ?? '')) ?: null,
            'citta' => trim((string) ($row['citta'] ?? '')) ?: null,
            'provincia' => strtoupper(trim((string) ($row['provincia'] ?? ''))) ?: null,
            'cap' => trim((string) ($row['cap'] ?? '')) ?: null,
            'stato' => trim((string) ($row['stato'] ?? '')) ?: null,
            'note' => trim((string) ($row['note'] ?? '')) ?: null,
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
