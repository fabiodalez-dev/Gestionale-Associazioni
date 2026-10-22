<?php

namespace App\Services\Soci;

use App\Models\Socio;
use App\Models\Tessera;
use Illuminate\Support\Facades\DB;

/**
 * Progressivi per associazione:
 * - numero socio: zero-padded a 3 cifre (001, 002, ...);
 * - numero tessera: zero-padded a 4 cifre (0001, 0002, ...).
 *
 * Calcolo come nel legacy (MAX numerico) ma dentro transazione con
 * lockForUpdate per mitigare le race condition (lock ignorato su sqlite).
 * L'espressione `colonna + 0` è portabile MySQL/sqlite (CAST AS UNSIGNED non lo è).
 */
class NumeroProgressivoService
{
    public function prossimoNumeroSocio(string $associazioneId): string
    {
        // Senza alcun global scope: l'unique (associazione_id, numero_socio) copre anche i soci
        // nel cestino e quelli fuori dal perimetro di sede dell'operatore che sta iscrivendo
        $max = Socio::query()
            ->withoutGlobalScopes()
            ->where('associazione_id', $associazioneId)
            ->when($this->supportsLock(), fn ($q) => $q->lockForUpdate())
            ->max(DB::raw('numero_socio + 0'));

        return str_pad((string) (((int) $max) + 1), 3, '0', STR_PAD_LEFT);
    }

    public function prossimoNumeroTessera(string $associazioneId): string
    {
        // Anche qui tutte le tessere dell'associazione, non solo quelle nel perimetro di sede
        $max = Tessera::query()
            ->withoutGlobalScopes()
            ->where('associazione_id', $associazioneId)
            ->when($this->supportsLock(), fn ($q) => $q->lockForUpdate())
            ->max(DB::raw('numero_tessera + 0'));

        return str_pad((string) (((int) $max) + 1), 4, '0', STR_PAD_LEFT);
    }

    private function supportsLock(): bool
    {
        return DB::connection()->getDriverName() !== 'sqlite';
    }
}
