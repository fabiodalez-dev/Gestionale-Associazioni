<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Limite dei tentativi di accesso (pannello, area soci, sito pubblico).
 *
 * - durata del blocco configurabile (AUTH_LOCKOUT_MINUTES, Impostazioni di sistema → Sicurezza);
 * - "azzera tentativi" dal super admin: le chiavi del limiter includono una
 *   versione, incrementarla rende nulli in un colpo tutti i contatori esistenti
 *   (il driver di cache non permette di elencarli e cancellarli uno per uno).
 */
final class LimiteAccessi
{
    private const CHIAVE_VERSIONE = 'limite-accessi:versione';

    public static function versione(): int
    {
        return (int) Cache::get(self::CHIAVE_VERSIONE, 1);
    }

    public static function chiave(string $base): string
    {
        return 'v'.self::versione().'|'.$base;
    }

    public static function azzera(): void
    {
        Cache::forever(self::CHIAVE_VERSIONE, self::versione() + 1);
    }

    public static function minutiBlocco(): int
    {
        return max(1, (int) config('auth.login_lockout_minutes', 1));
    }

    public static function secondiBlocco(): int
    {
        return self::minutiBlocco() * 60;
    }
}
