<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Engagement\Rekey\EngagementEphemeralTokenRekeyAdapter;
use Waaseyaa\Foundation\Security\ApplicationMasterEnvelope;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;

/** Purpose-bound submission and pagination tokens. @api */
final class EngagementTokens
{
    public const string SUBMISSION_PURPOSE = 'waaseyaa.engagement.submission.v1';
    public const string CURSOR_PURPOSE = 'waaseyaa.engagement.cursor.v1';
    public const int SUBMISSION_TTL = 172800;
    public const int CURSOR_TTL = 900;
    private const int SCHEMA_VERSION = 1;
    private const string SUBMISSION_IDENTITY = 'engagement-comment-submission';
    private const string CURSOR_IDENTITY = 'engagement-comments-cursor';

    /** @var \Closure():int */
    private readonly \Closure $clock;

    public function __construct(
        private readonly ApplicationMasterKeyring $keyring,
        private readonly string $installationId,
        ?\Closure $clock = null,
    ) {
        if ($installationId === '' || strlen($installationId) > 255) {
            throw new \InvalidArgumentException('Engagement installation ID must be non-empty and bounded.');
        }
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function issueSubmission(string $actorId, string $rootType, string $rootUuid, ?string $parentUuid): string
    {
        $now = ($this->clock)();
        return $this->seal(self::SUBMISSION_PURPOSE, self::SUBMISSION_IDENTITY, [
            'v' => self::SCHEMA_VERSION, 'installation' => $this->installationId,
            'actor' => $actorId, 'rootType' => $rootType, 'rootUuid' => $rootUuid,
            'parentUuid' => $parentUuid, 'nonce' => bin2hex(random_bytes(16)),
            'issuedAt' => $now, 'expiresAt' => $now + self::SUBMISSION_TTL,
        ]);
    }

    /** @return array{installation:string,actor:string,rootType:string,rootUuid:string,parentUuid:?string,nonce:string,issuedAt:int,expiresAt:int} */
    public function readSubmission(string $token): array
    {
        $claims = $this->open($token, self::SUBMISSION_PURPOSE, self::SUBMISSION_IDENTITY, 'VALIDATION_FAILED');
        if (($claims['v'] ?? null) !== self::SCHEMA_VERSION
            || ($claims['installation'] ?? null) !== $this->installationId
            || !is_string($claims['actor'] ?? null) || $claims['actor'] === ''
            || !is_string($claims['rootType'] ?? null) || $claims['rootType'] === ''
            || !is_string($claims['rootUuid'] ?? null) || $claims['rootUuid'] === ''
            || !(is_string($claims['parentUuid'] ?? null) || (array_key_exists('parentUuid', $claims) && $claims['parentUuid'] === null))
            || !is_string($claims['nonce'] ?? null) || preg_match('/^[a-f0-9]{32}$/D', $claims['nonce']) !== 1
            || !is_int($claims['issuedAt'] ?? null) || !is_int($claims['expiresAt'] ?? null)
            || $claims['issuedAt'] > ($this->clock)()
            || $claims['expiresAt'] !== $claims['issuedAt'] + self::SUBMISSION_TTL) {
            throw $this->invalid('Submission key is invalid.', null, 'VALIDATION_FAILED', 422);
        }
        if ($claims['expiresAt'] <= ($this->clock)()) {
            throw new EngagementException('SUBMISSION_EXPIRED', 409, 'Submission key has expired.');
        }
        return $claims;
    }

    /** @param array<string,mixed> $claims */
    public function issueCursor(array $claims): string
    {
        $now = ($this->clock)();
        return $this->seal(self::CURSOR_PURPOSE, self::CURSOR_IDENTITY, array_merge($claims, [
            'installation' => $this->installationId, 'v' => self::SCHEMA_VERSION,
            'expiresAt' => $now + self::CURSOR_TTL,
        ]));
    }

    /** @return array<string,mixed> */
    public function readCursor(string $token): array
    {
        $claims = $this->open($token, self::CURSOR_PURPOSE, self::CURSOR_IDENTITY);
        if (($claims['installation'] ?? null) !== $this->installationId || ($claims['v'] ?? null) !== self::SCHEMA_VERSION) {
            throw $this->invalid('Cursor is invalid.');
        }
        if (!is_int($claims['expiresAt'] ?? null)) {
            throw $this->invalid('Cursor is invalid.');
        }
        if ($claims['expiresAt'] <= ($this->clock)()) {
            throw new EngagementException('CURSOR_EXPIRED', 400, 'Cursor has expired.');
        }
        return $claims;
    }

    /** @return iterable<ApplicationMasterRekeyContribution> */
    public static function rekeyContributions(DatabaseInterface $database): iterable
    {
        yield new ApplicationMasterRekeyContribution(
            new EngagementEphemeralTokenRekeyAdapter($database),
            [
                new \Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy(
                    self::SUBMISSION_PURPOSE,
                    'waaseyaa/engagement',
                    \Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy::ReencryptCiphertext,
                    self::SUBMISSION_TTL,
                    self::SUBMISSION_TTL,
                    EngagementEphemeralTokenRekeyAdapter::ID,
                    'tokens-expire-naturally',
                ),
                new \Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy(
                    self::CURSOR_PURPOSE,
                    'waaseyaa/engagement',
                    \Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy::ReencryptCiphertext,
                    self::CURSOR_TTL,
                    self::CURSOR_TTL,
                    EngagementEphemeralTokenRekeyAdapter::ID,
                    'tokens-expire-naturally',
                ),
            ],
        );
    }

    /** @param array<string,mixed> $claims */
    private function seal(string $purpose, string $identity, array $claims): string
    {
        $envelope = $this->keyring->seal($purpose, $identity, self::SCHEMA_VERSION, json_encode($claims, JSON_THROW_ON_ERROR));
        return rtrim(strtr(base64_encode(json_encode($envelope->toArray(), JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array<string,mixed> */
    private function open(string $token, string $purpose, string $identity, string $invalidCode = 'INVALID_CURSOR'): array
    {
        try {
            if ($token === '' || strlen($token) > 8192 || preg_match('/^[A-Za-z0-9_-]+$/D', $token) !== 1) {
                throw new \InvalidArgumentException();
            }
            $wire = base64_decode(strtr($token, '-_', '+/') . str_repeat('=', (4 - strlen($token) % 4) % 4), true);
            $envelope = ApplicationMasterEnvelope::fromArray(json_decode($wire, true, 16, JSON_THROW_ON_ERROR));
            if ($envelope->purpose !== $purpose || $envelope->recordIdentity !== $identity || $envelope->schemaVersion !== self::SCHEMA_VERSION) {
                throw new \InvalidArgumentException();
            }
            $claims = json_decode($this->keyring->open($envelope), true, 16, JSON_THROW_ON_ERROR);
            if (!is_array($claims)) {
                throw new \InvalidArgumentException();
            }
            return $claims;
        } catch (\Throwable $failure) {
            throw $this->invalid('Token is invalid.', $failure, $invalidCode, $invalidCode === 'INVALID_CURSOR' ? 400 : 422);
        }
    }

    private function invalid(string $message, ?\Throwable $previous = null, string $code = 'INVALID_CURSOR', int $status = 400): EngagementException
    {
        return new EngagementException($code, $status, $message, null, $previous);
    }
}
