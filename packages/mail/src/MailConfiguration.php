<?php

declare(strict_types=1);

namespace Waaseyaa\Mail;

/** One authority for transport selection and auth-mail delivery capability. */
final readonly class MailConfiguration
{
    private function __construct(
        public string $transport,
        public string $fromAddress,
        public string $fromName,
        #[\SensitiveParameter]
        public string $sendgridKey,
        #[\SensitiveParameter]
        public array $smtp,
    ) {}

    public static function fromArray(#[\SensitiveParameter] array $config): self
    {
        $from = trim((string) ($config['from_address'] ?? ''));
        $key = trim((string) ($config['sendgrid_api_key'] ?? ''));
        $transport = $config['transport'] ?? ($key !== '' && $from !== '' ? 'sendgrid' : 'local');
        if (!in_array($transport, ['local', 'array', 'sendgrid', 'smtp'], true)) {
            throw new \InvalidArgumentException('Unknown mail transport.');
        }
        $smtp = $config['smtp'] ?? [];
        if ($transport === 'smtp') {
            if (!is_array($smtp) || $from === '' || !is_int($smtp['port'] ?? null) || $smtp['port'] < 1 || $smtp['port'] > 65535
                || !in_array($smtp['auth'] ?? null, ['login', 'xoauth2'], true)) {
                throw new \InvalidArgumentException('SMTP configuration is incomplete or invalid.');
            }
            foreach (['host', 'username', 'password'] as $field) {
                if (!is_string($smtp[$field] ?? null) || trim($smtp[$field]) === '' || preg_match('/[\r\n\x00]/', $smtp[$field])) {
                    throw new \InvalidArgumentException('SMTP configuration is incomplete or invalid.');
                }
            }
            if ($smtp['username'] === '0') {
                // Symfony treats this falsey string as unauthenticated relay.
                throw new \InvalidArgumentException('SMTP configuration is incomplete or invalid.');
            }
            if (preg_match('/[^a-zA-Z0-9.\-]/', $smtp['host'])) {
                throw new \InvalidArgumentException('SMTP host must be a hostname or IPv4 address.');
            }
            try {
                new \Symfony\Component\Mime\Address($from, (string) ($config['from_name'] ?? ''));
            } catch (\Throwable) {
                throw new \InvalidArgumentException('SMTP sender is invalid.');
            }
        }

        return new self($transport, $from, (string) ($config['from_name'] ?? ''), $key, $smtp);
    }

    public function canDeliver(): bool
    {
        return $this->fromAddress !== '' && ($this->transport === 'smtp' || ($this->transport === 'sendgrid' && $this->sendgridKey !== ''));
    }
}
