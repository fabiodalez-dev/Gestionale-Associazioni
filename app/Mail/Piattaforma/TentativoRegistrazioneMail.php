<?php

namespace App\Mail\Piattaforma;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Avviso al titolare di un'email già in uso: qualcuno ha provato a iscrivere
 * un'associazione indicandola come email dell'amministratore. Nessun dato
 * scritto nel modulo pubblico finisce nel messaggio.
 */
class TentativoRegistrazioneMail extends Mailable
{
    use Queueable;

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Tentativo di iscrizione con la tua email — '.config('app.name', 'Gestione Associazioni'));
    }

    public function content(): Content
    {
        return new Content(view: 'piattaforma.mail.tentativo-registrazione');
    }
}
