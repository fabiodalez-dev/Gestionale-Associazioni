<?php

namespace App\Exports\AnagraficaPro;

use App\Models\Associazione;
use App\Models\Tessera;
use Illuminate\Database\Eloquent\Builder;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Foglio "Tessere {anno}" dell'export multi-foglio (f.25).
 */
class TessereAnnoSheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly Associazione $associazione,
        private readonly int $anno,
    ) {}

    public function title(): string
    {
        return 'Tessere '.$this->anno;
    }

    public function query(): Builder
    {
        return Tessera::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->where('anno_validita', $this->anno)
            ->with('socio')
            ->orderBy('numero_tessera');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Numero Tessera', 'Anno', 'Numero Socio', 'Cognome', 'Nome',
            'Data Emissione', 'Data Scadenza', 'Stato',
        ];
    }

    /**
     * @param  Tessera  $tessera
     * @return list<mixed>
     */
    public function map(mixed $tessera): array
    {
        return [
            $tessera->numero_tessera,
            $tessera->anno_validita,
            $tessera->socio?->numero_socio,
            $tessera->socio?->cognome,
            $tessera->socio?->nome,
            $tessera->data_emissione->format('d/m/Y'),
            $tessera->data_scadenza->format('d/m/Y'),
            $tessera->stato,
        ];
    }
}
