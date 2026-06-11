<?php

namespace App\Mail\Struttura;

use App\Models\Associazione;
use App\Models\Socio;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Notifica all'associazione di una nuova richiesta di pre-iscrizione
 * dal form pubblico (best-effort: un errore di invio non blocca l'iscrizione).
 */
class NuovaPreiscrizioneMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public Associazione $associazione,
        public Socio $socio,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Nuova richiesta di pre-iscrizione — '.$this->associazione->nome,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'struttura.mail.nuova-preiscrizione',
        );
    }
}
