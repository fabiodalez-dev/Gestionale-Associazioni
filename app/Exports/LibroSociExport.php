<?php

namespace App\Exports;

use App\Services\Registro\LibroSociService;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Export Excel del libro soci ufficiale (f.13): le righe arrivano già
 * numerate e arricchite (delibera, cessazione) da LibroSociService.
 *
 * @phpstan-import-type RigaLibroSoci from LibroSociService
 */
class LibroSociExport implements FromArray, ShouldAutoSize, WithHeadings, WithMapping
{
    /**
     * @param  list<RigaLibroSoci>  $righe
     */
    public function __construct(private readonly array $righe) {}

    /**
     * @return list<RigaLibroSoci>
     */
    public function array(): array
    {
        return $this->righe;
    }

    /**
     * @return list<string>
     */
    public function headings(): array
    {
        return [
            'N. progressivo', 'Anno', 'Numero Socio', 'Cognome', 'Nome',
            'Codice Fiscale', 'Data di Nascita', 'Indirizzo', 'Tipo Socio',
            'Data Ammissione', 'Delibera N.', 'Delibera Data', 'Stato',
            'Data Cessazione', 'Tipo Cessazione', 'Motivo Cessazione',
        ];
    }

    /**
     * @param  RigaLibroSoci  $riga
     * @return list<mixed>
     */
    public function map(mixed $riga): array
    {
        $socio = $riga['socio'];
        $cessazione = $riga['cessazione'];

        return [
            $riga['progressivo'],
            $riga['anno'],
            $socio->numero_socio,
            $socio->cognome,
            $socio->nome,
            $socio->codice_fiscale,
            $socio->data_nascita?->format('d/m/Y'),
            trim(($socio->indirizzo ?? '').' '.($socio->cap ?? '').' '.($socio->citta ?? '').' '.($socio->provincia ?? '')),
            $socio->tipoSocio?->nome,
            // mai null: il libro include solo soci con data di ammissione
            $socio->data_iscrizione->format('d/m/Y'),
            $riga['delibera_numero'],
            $riga['delibera_data']?->format('d/m/Y'),
            $socio->stato,
            $cessazione?->data?->format('d/m/Y'),
            $cessazione?->tipo,
            $cessazione?->motivo,
        ];
    }
}
