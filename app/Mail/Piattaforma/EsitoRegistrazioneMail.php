<?php

namespace App\Mail\Piattaforma;

use App\Models\RichiestaRegistrazione;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Esito della richiesta all'amministratore indicato: accesso attivo oppure rifiuto.
 */
class EsitoRegistrazioneMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public RichiestaRegistrazione $richiesta) {}

    public function envelope(): Envelope
    {
        $app = config('app.name', 'Gestione Associazioni');

        return new Envelope(subject: $this->richiesta->stato === RichiestaRegistrazione::STATO_APPROVATA
            ? $this->richiesta->nome.' è attiva su '.$app
            : 'La richiesta di iscrizione a '.$app);
    }

    public function content(): Content
    {
        return new Content(view: 'piattaforma.mail.esito-registrazione');
    }
}
