<?php

namespace App\Mail\Struttura;

use App\Models\Utente;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invito a un nuovo amministratore: link firmato (7 giorni)
 * per impostare la propria password.
 */
class InvitoAmministratoreMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Utente $utente,
        public string $urlInvito,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Invito amministratore — '.config('app.name', 'Gestione Associazioni'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'struttura.mail.invito-amministratore',
        );
    }
}
