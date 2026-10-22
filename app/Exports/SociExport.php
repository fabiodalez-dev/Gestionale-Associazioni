<?php

namespace App\Exports;

use App\Models\Socio;
use App\Services\Soci\SociFilter;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export soci in Excel/CSV con gli stessi filtri della lista
 * (spec soci §1.11 + miglioramento 13).
 */
class SociExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly SociFilter $filter) {}

    public function query(): Builder
    {
        return $this->filter->build();
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Numero Socio', 'Cognome', 'Nome', 'Email', 'Telefono',
            'Codice Fiscale', 'Data di Nascita', 'Data Iscrizione', 'Stato',
            'Tipo Socio', 'Categoria', 'Sede', 'Indirizzo', 'Città', 'Provincia', 'CAP',
            'Numero Tessera', 'Scadenza Tessera', 'Tags', 'Note',
            // f.22 (piano "Gestione 2"): soci minorenni e tutore
            'Minorenne', 'Tutore', 'Telefono Tutore', 'Email Tutore',
            // f.20 (piano "Gestione 2"): nucleo familiare
            'Nucleo Familiare',
        ];
    }

    /**
     * @param  Socio  $socio
     * @return list<mixed>
     */
    public function map(mixed $socio): array
    {
        return [
            $socio->numero_socio,
            $socio->cognome,
            $socio->nome,
            $socio->email,
            $socio->telefono,
            $socio->codice_fiscale,
            $socio->data_nascita?->format('d/m/Y'),
            $socio->data_iscrizione->format('d/m/Y'),
            $socio->stato,
            $socio->tipoSocio?->nome,
            $socio->categoriaSocio?->nome,
            $socio->sede?->nome,
            $socio->indirizzo,
            $socio->citta,
            $socio->provincia,
            $socio->cap,
            $socio->ultimaTessera?->numero_tessera,
            $socio->ultimaTessera?->data_scadenza?->format('d/m/Y'),
            $socio->tags->pluck('nome_tag')->implode(', '),
            $socio->note,
            $socio->isMinorenne() ? 'Sì' : 'No',
            $socio->tutore_nome,
            $socio->tutore_telefono,
            $socio->tutore_email,
            $socio->nucleo?->nome,
        ];
    }
}
