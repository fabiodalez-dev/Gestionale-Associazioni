<?php

namespace App\Services\Iscrizioni;

use RuntimeException;

/**
 * Errore di dominio del flusso di iscrizione socio dal frontend pubblico.
 *
 * Il messaggio è in italiano e pensato per essere mostrato all'utente
 * (il controller lo riporta come errore di validazione, niente stack trace).
 */
class IscrizioneException extends RuntimeException
{
    /**
     * Vero quando l'esito NON va mostrato all'utente: il controller risponde
     * come per una richiesta accettata (modulo pubblico, niente oracolo).
     */
    public bool $rispostaNeutra = false;

    /**
     * Ospite anonimo con email già di un socio: la richiesta non viene creata
     * ma la risposta è identica al successo. Il messaggio esplicito resta
     * riservato agli account autenticati, titolari di quell'email.
     */
    public static function emailGiaSocioOspite(): self
    {
        $e = new self('Risulta già un socio con questa email.');
        $e->rispostaNeutra = true;

        return $e;
    }

    public static function iscrizioniChiuse(): self
    {
        return new self('Le iscrizioni a questa associazione non sono aperte.');
    }

    public static function giaSocio(): self
    {
        return new self('Il tuo account è già collegato a un socio.');
    }

    public static function emailGiaSocio(): self
    {
        return new self("Risulta già un socio con questa email: se sei tu, contatta l'associazione oppure accedi al tuo account.");
    }

    public static function richiestaGiaInAttesa(): self
    {
        return new self('Hai già una richiesta di iscrizione in attesa di approvazione.');
    }

    public static function richiestaGiaGestita(): self
    {
        return new self('Questa richiesta di iscrizione è già stata gestita.');
    }

    public static function socioPreiscrittoMancante(): self
    {
        return new self('Il socio preiscritto collegato alla richiesta non esiste più.');
    }

    public static function accountGiaCollegato(): self
    {
        return new self('L\'account della richiesta è già collegato a un altro socio.');
    }
}
