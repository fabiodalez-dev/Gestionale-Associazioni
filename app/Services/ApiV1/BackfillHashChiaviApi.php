<?php

namespace App\Services\ApiV1;

use Illuminate\Support\Facades\DB;

/**
 * Conversione delle chiavi API salvate in chiaro in hash sha256 + prefisso
 * (hardening audit run-1). Usata dalla migration 2026_11_13_110000 e
 * testabile in isolamento.
 *
 * Idempotente: tocca solo le righe con `api_key_hash` NULL, qualunque sia il
 * formato della chiave in chiaro (64 hex o altro); una riga già convertita o
 * una seconda esecuzione non ri-hashano mai nulla.
 */
class BackfillHashChiaviApi
{
    /**
     * @return int numero di chiavi convertite
     */
    public function esegui(): int
    {
        $convertite = 0;

        DB::table('api_keys')
            ->whereNull('api_key_hash')
            ->whereNotNull('api_key')
            ->where('api_key', '!=', '')
            ->orderBy('id')
            ->get(['id', 'api_key'])
            ->each(function (object $riga) use (&$convertite) {
                $convertite += DB::table('api_keys')
                    ->where('id', $riga->id)
                    ->whereNull('api_key_hash')
                    ->update([
                        'api_key_hash' => hash('sha256', (string) $riga->api_key),
                        'api_key_prefix' => substr((string) $riga->api_key, 0, 8),
                    ]);
            });

        return $convertite;
    }
}
