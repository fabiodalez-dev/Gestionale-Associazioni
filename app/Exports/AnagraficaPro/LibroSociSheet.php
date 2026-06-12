<?php

namespace App\Exports\AnagraficaPro;

use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Foglio "Libro soci" dell'export multi-foglio (f.25): registro cronologico
 * per data di ammissione con numero progressivo di iscrizione, da snapshot
 * reale dell'anagrafica del tenant.
 */
class LibroSociSheet implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly Associazione $associazione) {}

    public function title(): string
    {
        return 'Libro soci';
    }

    /**
     * @return Collection<int, array<int, mixed>>
     */
    public function collection(): Collection
    {
        return Socio::forAssociazione($this->associazione->id)
            ->orderBy('data_iscrizione')
            ->orderBy('created_at')
            ->get()
            ->values()
            ->map(fn (Socio $socio, int $indice): array => $this->riga($socio, $indice + 1));
    }

    /**
     * @return array<int, mixed>
     */
    private function riga(Socio $socio, int $numeroProgressivo): array
    {
        return [
            $numeroProgressivo,
            $socio->numero_socio,
            $socio->cognome,
            $socio->nome,
            $socio->data_nascita?->format('d/m/Y'),
            $socio->codice_fiscale,
            trim(($socio->indirizzo ?? '').' '.($socio->cap ?? '').' '.($socio->citta ?? '').' '.($socio->provincia ?? '')),
            $socio->data_iscrizione->format('d/m/Y'),
            $socio->stato,
        ];
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'N. progressivo', 'Numero Socio', 'Cognome', 'Nome', 'Data di Nascita',
            'Codice Fiscale', 'Indirizzo', 'Data Ammissione', 'Stato',
        ];
    }
}
