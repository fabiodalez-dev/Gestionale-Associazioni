<?php

namespace App\Services\Email;

use RuntimeException;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Driver mailer "smtp-pinned": come il driver smtp di Laravel, ma la
 * connessione va all'IP già risolto e validato (HostSmtpPubblico), mentre
 * il nome originale resta il peer TLS (SNI e verifica del certificato).
 * Così tra la validazione e la connessione il DNS non può cambiare
 * indirizzo verso la rete interna (rebinding).
 *
 * Config attesa: host (IP validato oppure nome, se la risoluzione è stata
 * saltata), peer_name (nome originale), port, username, password, timeout,
 * scheme (smtp|smtps), auto_tls.
 */
class SmtpPinnedTransport
{
    /**
     * @param  array<string, mixed>  $config
     */
    public static function crea(array $config): EsmtpTransport
    {
        $scheme = (string) ($config['scheme'] ?? 'smtp');

        $transport = (new EsmtpTransportFactory)->create(new Dsn(
            $scheme,
            (string) $config['host'],
            $config['username'] ?? null,
            $config['password'] ?? null,
            isset($config['port']) ? (int) $config['port'] : null,
            $config,
        ));

        if (! $transport instanceof EsmtpTransport) {
            throw new RuntimeException('Il factory SMTP di Symfony non ha restituito un EsmtpTransport.');
        }

        $stream = $transport->getStream();
        if ($stream instanceof SocketStream) {
            $stream->setTimeout((int) ($config['timeout'] ?? 30));

            $opzioni = $stream->getStreamOptions();
            $opzioni['ssl']['peer_name'] = (string) ($config['peer_name'] ?? $config['host']);
            $stream->setStreamOptions($opzioni);
        }

        return $transport;
    }
}
