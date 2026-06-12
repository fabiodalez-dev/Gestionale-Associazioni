<?php

namespace App\Exports;

use App\Models\Quota;
use App\Models\Socio;
use App\Models\Tessera;
use App\Services\Rinnovi\StoricoTesseramentoService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * Export Excel dello storico tesseramento di un singolo socio (f.6):
 * una riga per anno con tessere, quote/pagamenti e presenze eventi.
 */
class StoricoTesseramentoExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(
        private readonly Socio $socio,
        private readonly StoricoTesseramentoService $storico,
    ) {}

    public function title(): string
    {
        return 'Storico tesseramento';
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Anno', 'Tessere', 'Scadenza tessera', 'Stato tessera',
            'Quote', 'Importo quote', 'Importo pagato', 'Pagamenti', 'Presenze eventi',
        ];
    }

    public function collection()
    {
        return $this->storico->timeline($this->socio)->map(function (array $riga) {
            /** @var Collection<int, Tessera> $tessere */
            $tessere = $riga['tessere'];
            /** @var Collection<int, Quota> $quote */
            $quote = $riga['quote'];

            $pagamenti = $quote
                ->filter(fn (Quota $q) => $q->isPagata())
                ->map(fn (Quota $q) => trim(($q->data_pagamento?->format('d/m/Y') ?? '').' '.($q->metodo_pagamento ? '('.$q->metodo_pagamento.')' : '')))
                ->implode('; ');

            return [
                $riga['anno'],
                $tessere->map(fn (Tessera $t) => 'n. '.$t->numero_tessera)->implode('; '),
                $tessere->map(fn (Tessera $t) => $t->data_scadenza->format('d/m/Y'))->implode('; '),
                $tessere->map(fn (Tessera $t) => $t->statoVisualizzato())->implode('; '),
                $quote->map(fn (Quota $q) => $q->tipo)->implode('; '),
                (float) $quote->sum(fn (Quota $q) => (float) $q->importo),
                (float) $quote->filter(fn (Quota $q) => $q->isPagata())->sum(fn (Quota $q) => (float) $q->importo),
                $pagamenti,
                $riga['presenze'],
            ];
        });
    }
}
