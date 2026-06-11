<?php

namespace App\Data\ApiV1;

use App\Models\Tessera;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Riga tessera nelle liste/ricerche API v1 (miglioramento 15: DTO laravel-data).
 *
 * Le colonne associazione_id/associazione_nome compaiono solo per i client globali.
 */
class TesseraListItemData extends Data
{
    public function __construct(
        public string $id,
        public ?string $numero_tessera,
        public ?int $anno_validita,
        public ?string $data_emissione,
        public ?string $data_scadenza,
        public ?string $stato,
        public ?string $socio_nome,
        public ?string $socio_cognome,
        public ?string $numero_socio,
        public ?string $socio_id,
        public string|Optional|null $associazione_id,
        public string|Optional|null $associazione_nome,
    ) {}

    public static function fromTessera(Tessera $tessera, bool $globale): self
    {
        return new self(
            id: $tessera->id,
            numero_tessera: $tessera->numero_tessera,
            anno_validita: $tessera->anno_validita,
            data_emissione: $tessera->data_emissione->format('Y-m-d'),
            data_scadenza: $tessera->data_scadenza->format('Y-m-d'),
            stato: $tessera->stato,
            socio_nome: $tessera->socio?->nome,
            socio_cognome: $tessera->socio?->cognome,
            numero_socio: $tessera->socio?->numero_socio,
            socio_id: $tessera->socio_id,
            associazione_id: $globale ? $tessera->associazione_id : Optional::create(),
            associazione_nome: $globale ? $tessera->associazione?->nome : Optional::create(),
        );
    }
}
