<?php

namespace App\Data\ApiV1;

use App\Models\Socio;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Riga socio nelle liste/ricerche API v1 (miglioramento 15: DTO laravel-data).
 */
class SocioListItemData extends Data
{
    public function __construct(
        public string $id,
        public ?string $numero_socio,
        public ?string $nome,
        public ?string $cognome,
        public ?string $email,
        public ?string $telefono,
        public ?string $stato,
        public ?string $data_iscrizione,
        public string|Optional|null $associazione_id,
        public string|Optional|null $associazione_nome,
    ) {}

    public static function fromSocio(Socio $socio, bool $globale): self
    {
        return new self(
            id: $socio->id,
            numero_socio: $socio->numero_socio,
            nome: $socio->nome,
            cognome: $socio->cognome,
            email: $socio->email,
            telefono: $socio->telefono,
            stato: $socio->stato,
            data_iscrizione: $socio->data_iscrizione->format('Y-m-d'),
            associazione_id: $globale ? $socio->associazione_id : Optional::create(),
            associazione_nome: $globale ? $socio->associazione?->nome : Optional::create(),
        );
    }
}
