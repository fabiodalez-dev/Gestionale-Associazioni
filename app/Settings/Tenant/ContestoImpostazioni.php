<?php

namespace App\Settings\Tenant;

use Closure;
use LogicException;

/**
 * Associazione a cui si riferiscono le letture e scritture del
 * TenantSettingsRepository. Impostata solo per la durata di una closure, così
 * un comando o un job che scorre più associazioni non usa mai quella sbagliata
 * (la sessione non c'è o non c'entra).
 */
final class ContestoImpostazioni
{
    private static ?string $associazioneId = null;

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function con(string $associazioneId, Closure $callback): mixed
    {
        $precedente = self::$associazioneId;
        self::$associazioneId = $associazioneId;

        try {
            return $callback();
        } finally {
            self::$associazioneId = $precedente;
        }
    }

    public static function richiesto(): string
    {
        return self::$associazioneId
            ?? throw new LogicException('Impostazioni di associazione lette fuori contesto: usare ImpostazioniAssociazione::per($associazione).');
    }
}
