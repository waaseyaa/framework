<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Config;

final readonly class AuthConfig
{
    private const array DEFAULT_TOKEN_TTLS = [
        'password_reset' => 3600,
        'email_verification' => 86400,
        'invite' => 604800,
    ];

    private const array VALID_REGISTRATION_MODES = ['admin', 'open', 'invite'];

    private const array DEV_ENVIRONMENTS = ['local', 'development', 'dev'];

    public function __construct(
        public readonly string $registration,
        public readonly bool $requireVerifiedEmail,
        public readonly MailMissingPolicy $mailMissingPolicy,
        public readonly string $tokenSecret,
        private readonly array $tokenTtls,
    ) {}

    public static function fromArray(array $config, string $appEnv = 'production'): self
    {
        $registration = $config['registration'] ?? 'admin';
        if (!in_array($registration, self::VALID_REGISTRATION_MODES, true)) {
            $registration = 'admin';
        }

        $requireVerifiedEmail = (bool) ($config['require_verified_email'] ?? false);

        $mailMissingPolicy = self::resolveMailPolicy($config['mail_missing_policy'] ?? null, $appEnv);

        $tokenSecret = (string) ($config['token_secret'] ?? '');

        $tokenTtls = array_merge(
            self::DEFAULT_TOKEN_TTLS,
            $config['token_ttls'] ?? [],
        );

        return new self(
            registration: $registration,
            requireVerifiedEmail: $requireVerifiedEmail,
            mailMissingPolicy: $mailMissingPolicy,
            tokenSecret: $tokenSecret,
            tokenTtls: $tokenTtls,
        );
    }

    public function tokenTtl(string $type): int
    {
        return $this->tokenTtls[$type] ?? self::DEFAULT_TOKEN_TTLS[$type] ?? 0;
    }

    private static function resolveMailPolicy(?string $configured, string $appEnv): MailMissingPolicy
    {
        if ($configured !== null) {
            return MailMissingPolicy::from($configured);
        }

        if (in_array($appEnv, self::DEV_ENVIRONMENTS, true)) {
            return MailMissingPolicy::DevLog;
        }

        return MailMissingPolicy::Fail;
    }
}
