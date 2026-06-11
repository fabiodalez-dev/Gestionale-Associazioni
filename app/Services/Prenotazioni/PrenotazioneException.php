<?php

namespace App\Services\Prenotazioni;

use RuntimeException;

/**
 * Errore di dominio della macchina a stati prenotazioni.
 *
 * Il messaggio è in italiano e pensato per essere mostrato all'utente
 * (il controller lo riporta come errore di validazione, niente stack trace).
 */
class PrenotazioneException extends RuntimeException
{
    public static function eventoNonPrenotabile(): self
    {
        return new self('Le prenotazioni per questo evento non sono aperte.');
    }

    public static function postiNonValidi(int $max): self
    {
        return new self("Puoi prenotare da 1 a {$max} posti per questo evento.");
    }

    public static function doppiaPrenotazione(): self
    {
        return new self('Hai già una prenotazione attiva per questo evento.');
    }

    public static function postiEsauriti(): self
    {
        return new self('I posti per questo evento sono esauriti.');
    }

    public static function tenantNonCoerente(): self
    {
        return new self('La prenotazione non appartiene a questa associazione.');
    }

    public static function nonAutorizzato(): self
    {
        return new self('Non sei autorizzato a operare su questa prenotazione.');
    }

    public static function nonAnnullabile(string $motivo = ''): self
    {
        return new self(trim('Questa prenotazione non può essere annullata. '.$motivo));
    }

    public static function nonInApprovazione(): self
    {
        return new self('La prenotazione non è in attesa di approvazione.');
    }

    public static function annullamentoOltreTermine(int $ore): self
    {
        return new self(
            "Il termine per l'annullamento è scaduto: era possibile annullare "
            ."fino a {$ore} ore prima dell'evento."
        );
    }
}
