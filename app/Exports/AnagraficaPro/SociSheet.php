<?php

namespace App\Exports\AnagraficaPro;

use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Foglio "Soci" dell'export multi-foglio (f.25): anagrafica completa del tenant.
 */
class SociSheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(private readonly Associazione $associazione) {}

    public function title(): string
    {
        return 'Soci';
    }

    public function query(): Builder
    {
        return Socio::forAssociazione($this->associazione->id)
            ->with(['tipoSocio', 'categoriaSocio', 'sede', 'ultimaTessera'])
            ->orderBy('cognome')
            ->orderBy('nome');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Numero Socio', 'Cognome', 'Nome', 'Email', 'Telefono', 'Codice Fiscale',
            'Data di Nascita', 'Data Iscrizione', 'Stato', 'Tipo Socio', 'Categoria',
            'Sede', 'Indirizzo', 'Città', 'Provincia', 'CAP', 'Numero Tessera',
            'Scadenza Tessera', 'Note',
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
            $socio->note,
        ];
    }
}
