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
                $numeroRiga = $indice + 2; // +1 heading row, +1 base 1
                $dati = $this->normalizza($row->toArray());

                $validator = Validator::make($dati, $this->regole(), [], [
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
     * @return array<string, mixed>
     */
    private function regole(): array
    {
        $assocId = $this->associazione->id;

        return [
            'nome' => ['required', 'string', 'max:100'],
            'cognome' => ['required', 'string', 'max:100'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('soci', 'email')->where('associazione_id', $assocId),
            ],
            'codice_fiscale' => [
                'nullable', 'string', 'max:16', 'codice_fiscale',
                Rule::unique('soci', 'codice_fiscale')->where('associazione_id', $assocId),
            ],
            'telefono' => ['nullable', 'string', 'max:20'],
            'data_nascita' => ['nullable', 'date'],
            'data_iscrizione' => ['nullable', 'date'],
            'stato' => ['nullable', Rule::in(Socio::STATI)],
            'numero_socio' => [
                'nullable', 'string', 'max:50',
                Rule::unique('soci', 'numero_socio')->where('associazione_id', $assocId),
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
