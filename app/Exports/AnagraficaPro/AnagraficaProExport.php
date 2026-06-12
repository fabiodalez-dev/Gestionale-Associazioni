<?php

namespace App\Exports\AnagraficaPro;

use App\Models\Associazione;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export Excel multi-foglio (f.25 PIANO-VITA-SOCIALE):
 * Soci, Tessere {anno}, Quote {anno}, Libro soci.
 */
class AnagraficaProExport implements WithMultipleSheets
{
    public function __construct(
        private readonly Associazione $associazione,
        private readonly int $anno,
    ) {}

    /**
     * @return array<int, object>
     */
    public function sheets(): array
    {
        return [
            new SociSheet($this->associazione),
            new TessereAnnoSheet($this->associazione, $this->anno),
            new QuoteAnnoSheet($this->associazione, $this->anno),
            new LibroSociSheet($this->associazione),
        ];
    }
}
