<?php

namespace App\Exports\Operativita;

use App\Exports\SociExport;
use App\Models\Socio;
use App\Services\Soci\SociFilter;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export della selezione di soci dalle azioni in blocco (f.2 del piano
 * "Gestione 2"). Riusa intestazioni e mappatura di SociExport (stesse
 * colonne dell'export completo), limitando la query agli id selezionati
 * (tenant-scoped dal global scope di Socio).
 */
class SociSelezioneExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping
{
    private readonly SociExport $base;

    /**
     * @param  list<string>  $sociIds
     */
    public function __construct(private readonly array $sociIds)
    {
        $this->base = new SociExport(new SociFilter([]));
    }

    public function query(): Builder
    {
        return Socio::query()
            ->with(['tipoSocio', 'categoriaSocio', 'sede', 'tags', 'ultimaTessera'])
            ->whereIn('id', $this->sociIds)
            ->orderBy('cognome')
            ->orderBy('nome');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return $this->base->headings();
    }

    /**
     * @param  Socio  $socio
     * @return list<mixed>
     */
    public function map(mixed $socio): array
    {
        return $this->base->map($socio);
    }
}
