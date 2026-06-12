<?php

namespace App\Exports\AnagraficaPro;

use App\Models\Associazione;
use App\Models\Quota;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Foglio "Quote {anno}" dell'export multi-foglio (f.25).
 */
class QuoteAnnoSheet implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithTitle
{
    public function __construct(
        private readonly Associazione $associazione,
        private readonly int $anno,
    ) {}

    public function title(): string
    {
        return 'Quote '.$this->anno;
    }

    public function query()
    {
        return Quota::allTenants()
            ->where('associazione_id', $this->associazione->id)
            ->where('anno', $this->anno)
            ->with('socio')
            ->orderBy('created_at');
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Anno', 'Numero Socio', 'Cognome', 'Nome', 'Importo',
            'Stato', 'Data Pagamento', 'Metodo Pagamento', 'Note',
        ];
    }

    /**
     * @param  Quota  $quota
     * @return list<mixed>
     */
    public function map($quota): array
    {
        return [
            $quota->anno,
            $quota->socio?->numero_socio,
            $quota->socio?->cognome,
            $quota->socio?->nome,
            (float) $quota->importo,
            $quota->stato,
            $quota->data_pagamento?->format('d/m/Y'),
            $quota->metodo_pagamento,
            $quota->note,
        ];
    }
}
