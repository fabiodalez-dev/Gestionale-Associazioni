<?php

namespace App\Mail\Piattaforma;

use App\Models\RichiestaRegistrazione;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Link firmato per confermare l'email di chi iscrive l'associazione.
 */
class VerificaEmailRegistrazioneMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public RichiestaRegistrazione $richiesta, public string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Conferma l\'iscrizione di '.$this->richiesta->nome);
    }

    public function content(): Content
    {
        return new Content(view: 'piattaforma.mail.verifica-email');
    }
}
