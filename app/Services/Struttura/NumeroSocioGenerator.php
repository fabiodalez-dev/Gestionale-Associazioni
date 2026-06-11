<?php

namespace App\Services\Struttura;

use App\Models\Socio;
use Illuminate\Support\Facades\DB;

/**
 * Numero socio progressivo per tenant: MAX(numero_socio as int) + 1,
 * con padding a 3 cifre (001, 002, ... — oltre 999 cresce senza padding extra).
 *
 * Da chiamare DENTRO una transazione: usa lockForUpdate per eliminare
 * la race condition del MAX()+1 legacy (spec struttura §6.7.2, §9.11).
 */
class NumeroSocioGenerator
{
    public function next(string $associazioneId): string
    {
        $cast = DB::connection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'UNSIGNED';

        $max = Socio::query()
            ->withoutGlobalScopes()
            ->where('associazione_id', $associazioneId)
            ->lockForUpdate()
            ->max(DB::raw("CAST(numero_socio AS {$cast})"));

        return str_pad((string) (((int) $max) + 1), 3, '0', STR_PAD_LEFT);
    }
}
