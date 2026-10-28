<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope as SymfonyEnvelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface as SymfonyTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailConfiguration;
use Waaseyaa\Mail\Transport\SmtpTransport;

final class SmtpTransportTest extends TestCase
{
    public function test_multipart_message_and_custom_headers_reach_the_maintained_transport(): void
    {
        $sink = new class implements SymfonyTransport {
            public ?Email $message = null;
            public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SentMessage
            {
                $this->message = $message;
                return null;
            }
            public function __toString(): string
            {
                return 'test';
            }
        };
        new SmtpTransport($sink, 'Nation website')->send(new Envelope(
            to: ['member@example.test', 'second@example.test'],
            from: 'webforms@example.test',
            subject: 'Welcome',
            textBody: 'Plain message',
            htmlBody: '<p>Message</p>',
            headers: ['X-Request-ID' => 'request-1'],
        ));
        self::assertSame('Nation website', $sink->message->getFrom()[0]->getName());
        self::assertCount(2, $sink->message->getTo());
        self::assertSame('Plain message', $sink->message->getTextBody());
        self::assertSame('<p>Message</p>', $sink->message->getHtmlBody());
        self::assertSame('request-1', $sink->message->getHeaders()->get('X-Request-ID')->getBodyAsString());
    }

    public function test_delivery_failure_is_real_and_does_not_expose_transcripts_or_previous_exceptions(): void
    {
        $sink = new class implements SymfonyTransport {
            public function send(RawMessage $message, ?SymfonyEnvelope $envelope = null): ?SentMessage
            {
                throw new TransportException('AUTH LOGIN secret-token message-body reset-token');
            }
            public function __toString(): string
            {
                return 'test';
            }
        };
        try {
            new SmtpTransport($sink)->send(new Envelope(['member@example.test'], 'webforms@example.test', 'Reset', 'reset-token'));
            self::fail('Delivery failure must propagate.');
        } catch (\RuntimeException $error) {
            self::assertSame('SMTP delivery failed.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_headers_cannot_override_routing_or_mime(): void
    {
        $transport = SmtpTransport::fromConfiguration(MailConfiguration::fromArray(self::configuration()));
        foreach (['Bcc', 'To', 'From', 'Subject', 'Content-Type', 'Return-Path'] as $header) {
            try {
                $transport->send(new Envelope(['member@example.test'], 'webforms@example.test', 'Reset', 'Body', headers: [$header => 'other@example.test']));
                self::fail('Reserved header must refuse before networking.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('SMTP envelope contains a reserved header.', $error->getMessage());
            }
        }
    }

    public function test_smtp_configuration_is_complete_and_transport_selection_is_explicit(): void
    {
        self::assertTrue(MailConfiguration::fromArray(self::configuration())->canDeliver());
        self::assertFalse(MailConfiguration::fromArray(['transport' => 'local'])->canDeliver());
        self::assertFalse(MailConfiguration::fromArray(['transport' => 'array', 'sendgrid_api_key' => 'old', 'from_address' => 'sender@example.test'])->canDeliver());
        foreach (['host', 'port', 'username', 'password', 'auth'] as $key) {
            $config = self::configuration();
            unset($config['smtp'][$key]);
            try {
                MailConfiguration::fromArray($config);
                self::fail('Incomplete SMTP configuration must refuse.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringNotContainsString('test-password', $error->getMessage());
            }
        }
        $invalid = self::configuration();
        $invalid['smtp']['username'] = '0';
        try {
            MailConfiguration::fromArray($invalid);
            self::fail('A username that Symfony treats as unauthenticated must refuse.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('SMTP configuration is incomplete or invalid.', $error->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        MailConfiguration::fromArray(['transport' => 'smtpp']);
    }

    public static function configuration(): array
    {
        return ['transport' => 'smtp', 'from_address' => 'webforms@example.test', 'smtp' => [
            'host' => 'smtp.office365.com', 'port' => 587, 'username' => 'webforms@example.test', 'password' => 'test-password', 'auth' => 'login',
        ]];
    }
}
