<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Transport;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Auth\LoginAuthenticator;
use Symfony\Component\Mailer\Transport\Smtp\Auth\XOAuth2Authenticator;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Symfony\Component\Mailer\Transport\TransportInterface as SymfonyTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailConfiguration;

/** Minimal envelope adapter; Symfony owns SMTP, MIME, TLS and authentication. */
final class SmtpTransport implements TransportInterface
{
    public function __construct(private readonly SymfonyTransport $transport, private readonly string $fromName = '') {}

    public static function fromConfiguration(#[\SensitiveParameter] MailConfiguration $config): self
    {
        if ($config->transport !== 'smtp') {
            throw new \InvalidArgumentException('SMTP configuration required.');
        }
        $smtp = $config->smtp;
        $transport = new AuthenticatedEsmtpTransport(
            host: $smtp['host'],
            port: $smtp['port'],
            tls: false,
            authenticators: [$smtp['auth'] === 'xoauth2' ? new XOAuth2Authenticator() : new LoginAuthenticator()],
        );
        $transport->setRequireTls(true);
        $transport->setUsername($smtp['username']);
        $transport->setPassword($smtp['password']);
        $stream = $transport->getStream();
        if (!$stream instanceof SocketStream) {
            throw new \LogicException('SMTP requires a socket stream.');
        }
        $stream->setTimeout(10);
        $stream->setStreamOptions(['ssl' => [
            'verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $smtp['host'], 'allow_self_signed' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]]);

        return new self($transport, $config->fromName);
    }

    public function send(#[\SensitiveParameter] Envelope $envelope): void
    {
        $email = new Email()->from(new Address($envelope->from, $this->fromName))->to(...$envelope->to)->subject($envelope->subject);
        if ($envelope->textBody !== '') {
            $email->text($envelope->textBody);
        }
        if ($envelope->htmlBody !== '') {
            $email->html($envelope->htmlBody);
        }
        foreach ($envelope->headers as $name => $value) {
            if (in_array(strtolower($name), ['to', 'from', 'cc', 'bcc', 'sender', 'return-path', 'subject', 'content-type', 'content-transfer-encoding', 'mime-version', 'date', 'message-id'], true)) {
                throw new \InvalidArgumentException('SMTP envelope contains a reserved header.');
            }
            if (strtolower($name) === 'reply-to') {
                $email->replyTo($value);
            } else {
                $email->getHeaders()->addTextHeader($name, $value);
            }
        }
        try {
            $this->transport->send($email);
        } catch (TransportExceptionInterface) {
            // Provider transcripts can include AUTH payloads and reset tokens.
            // Deliberately do not chain the original exception or its debug log.
            throw new \RuntimeException('SMTP delivery failed.');
        }
    }
}
