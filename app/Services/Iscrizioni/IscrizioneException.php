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
