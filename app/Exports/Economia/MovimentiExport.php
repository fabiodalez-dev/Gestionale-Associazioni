<?php

namespace App\Exports\Economia;

use App\Models\Movimento;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export Excel del report economico (f.14 del piano "Gestione 2"):
 * movimenti del periodo con categoria e origine.
 */
class MovimentiExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  Collection<int, Movimento>  $movimenti
     */
    public function __construct(private readonly Collection $movimenti) {}

    /**
     * @return Collection<int, Movimento>
     */
    public function collection(): Collection
    {
        return $this->movimenti;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'Data', 'Tipo', 'Categoria', 'Descrizione', 'Metodo',
            'Entrata (€)', 'Uscita (€)', 'Origine',
        ];
    }

    /**
     * @param  Movimento  $movimento
     * @return list<mixed>
     */
    public function map($movimento): array
    {
        return [
            $movimento->data->format('d/m/Y'),
            ucfirst($movimento->tipo),
            $movimento->categoria->nome ?? '',
            $movimento->descrizione,
            $movimento->metodo,
            $movimento->tipo === Movimento::TIPO_ENTRATA ? (float) $movimento->importo : null,
            $movimento->tipo === Movimento::TIPO_USCITA ? (float) $movimento->importo : null,
            $movimento->origine_id !== null ? 'Automatico ('.class_basename((string) $movimento->origine_type).')' : 'Manuale',
        ];
    }
}
