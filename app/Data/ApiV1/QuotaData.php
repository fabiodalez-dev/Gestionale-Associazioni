<?php

namespace App\Data\ApiV1;

use App\Models\Quota;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Quota nelle risposte API v1 (miglioramento 15: DTO laravel-data).
 *
 * I dati del socio (nome/cognome/numero) compaiono solo nella lista paginata
 * /quote; in /quote/{socio_id} il socio è già nel payload esterno.
 */
class QuotaData extends Data
{
    public function __construct(
        public string $id,
        public ?int $anno,
        public ?string $importo,
        public ?string $data_scadenza,
        public ?string $data_pagamento,
        public ?string $stato,
        public ?string $tipo,
        public ?string $note,
        public string|Optional|null $nome,
        public string|Optional|null $cognome,
        public string|Optional|null $numero_socio,
        public string|Optional|null $associazione_id,
        public string|Optional|null $associazione_nome,
    ) {}

    public static function fromQuota(Quota $quota, bool $conSocio = false, bool $globale = false): self
    {
        return new self(
            id: $quota->id,
            anno: $quota->anno,
            importo: $quota->importo,
            data_scadenza: $quota->data_scadenza->format('Y-m-d'),
            data_pagamento: $quota->data_pagamento?->format('Y-m-d'),
            stato: $quota->stato,
            tipo: $quota->tipo,
            note: $quota->note,
            nome: $conSocio ? $quota->socio?->nome : Optional::create(),
            cognome: $conSocio ? $quota->socio?->cognome : Optional::create(),
            numero_socio: $conSocio ? $quota->socio?->numero_socio : Optional::create(),
            associazione_id: $globale ? $quota->associazione_id : Optional::create(),
            associazione_nome: $globale ? $quota->associazione?->nome : Optional::create(),
        );
    }
}
