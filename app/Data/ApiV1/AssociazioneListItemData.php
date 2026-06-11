<?php

namespace App\Data\ApiV1;

use App\Models\Associazione;
use Spatie\LaravelData\Data;

/**
 * Riga associazione in GET /api/v1/associazioni (solo client globali).
 */
class AssociazioneListItemData extends Data
{
    public function __construct(
        public string $id,
        public ?string $nome,
        public ?string $email,
        public ?string $citta,
        public ?string $provincia,
        public bool $attiva,
    ) {}

    public static function fromAssociazione(Associazione $associazione): self
    {
        return new self(
            id: $associazione->id,
            nome: $associazione->nome,
            email: $associazione->email,
            citta: $associazione->citta,
            provincia: $associazione->provincia,
            attiva: (bool) $associazione->attiva,
        );
    }
}
