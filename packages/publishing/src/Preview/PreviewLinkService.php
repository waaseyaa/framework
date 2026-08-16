<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Preview;

use Waaseyaa\Foundation\Security\ApplicationMasterAuthenticationTag;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\SensitiveKey;

/**
 * Short-lived signed preview grants for unpublished content.
 *
 * Legacy signatures are HMAC-SHA256 over `type|id|expiresAt`. Governed editor
 * previews use a domain-separated signature over type, id, exact revision, and
 * expiry. Both use a purpose-derived secret or versioned application-master
 * custody. The package ships
 * no HTTP route: the app wires a preview route, verifies the grant, renders
 * the selected revision through its real layout, and MUST mark the response
 * `noindex, nofollow` (header and meta). Verification is constant-time and
 * an expired grant never verifies.
 *
 * @api
 */
final class PreviewLinkService
{
    public const int DEFAULT_TTL_SECONDS = 1800;

    private const string PREFIX = 'hmac-sha256.application-master.preview.v1:';
    private const string ENTITY_DOMAIN = "waaseyaa.publishing.preview.entity.v1\0";
    private const string REVISION_DOMAIN = "waaseyaa.publishing.preview.revision.v1\0";

    /** @var \Closure(): int Injectable clock for tests; defaults to time(). */
    private readonly \Closure $clock;
    private readonly ?SensitiveKey $legacySecret;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        #[\SensitiveParameter]
        ?string $secret,
        ?\Closure $clock = null,
        private readonly ?ApplicationMasterKeyring $keyring = null,
    ) {
        if ($secret === '') {
            throw new \InvalidArgumentException('Preview secret must not be empty.');
        }
        if ($secret === null && $keyring === null) {
            throw new \InvalidArgumentException('Preview signing requires legacy or application-master custody.');
        }
        $this->legacySecret = $secret === null ? null : new SensitiveKey($secret);
        $this->clock = $clock ?? static fn(): int => time();
    }

    /** @param (\Closure(): int)|null $clock */
    public static function fromApplicationMasterKeyring(
        ApplicationMasterKeyring $keyring,
        #[\SensitiveParameter]
        ?string $legacySecret = null,
        ?\Closure $clock = null,
    ): self {
        return new self($legacySecret, $clock, $keyring);
    }

    public function issue(string $entityTypeId, string $entityId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): PreviewToken
    {
        $this->assertTtl($ttlSeconds);
        $expiresAt = ($this->clock)() + $ttlSeconds;

        return new PreviewToken(
            $entityTypeId,
            $entityId,
            $expiresAt,
            $this->sign($entityTypeId, $entityId, $expiresAt),
        );
    }

    public function verify(string $entityTypeId, string $entityId, int $expiresAt, string $signature): bool
    {
        if ($expiresAt <= ($this->clock)()) {
            return false;
        }

        return $this->verifySignature(
            self::ENTITY_DOMAIN . $this->entityMessage($entityTypeId, $entityId, $expiresAt),
            $signature,
            fn(): string => $this->legacySign($entityTypeId, $entityId, $expiresAt),
        );
    }

    public function issueRevision(
        string $entityTypeId,
        string $entityId,
        int $revisionId,
        int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ): RevisionPreviewToken {
        if ($revisionId < 1) {
            throw new \InvalidArgumentException('Preview revision must be positive.');
        }
        $this->assertTtl($ttlSeconds);
        $expiresAt = ($this->clock)() + $ttlSeconds;

        return new RevisionPreviewToken(
            $entityTypeId,
            $entityId,
            $revisionId,
            $expiresAt,
            $this->signRevision($entityTypeId, $entityId, $revisionId, $expiresAt),
        );
    }

    public function verifyRevision(
        string $entityTypeId,
        string $entityId,
        int $revisionId,
        int $expiresAt,
        string $signature,
    ): bool {
        if ($revisionId < 1 || $expiresAt <= ($this->clock)()) {
            return false;
        }

        return $this->verifySignature(
            self::REVISION_DOMAIN . $this->revisionMessage($entityTypeId, $entityId, $revisionId, $expiresAt),
            $signature,
            fn(): string => $this->legacySignRevision($entityTypeId, $entityId, $revisionId, $expiresAt),
        );
    }

    private function sign(string $entityTypeId, string $entityId, int $expiresAt): string
    {
        if ($this->keyring !== null) {
            return $this->sealVersioned(
                self::ENTITY_DOMAIN . $this->entityMessage($entityTypeId, $entityId, $expiresAt),
            );
        }

        return $this->legacySign($entityTypeId, $entityId, $expiresAt);
    }

    private function signRevision(string $entityTypeId, string $entityId, int $revisionId, int $expiresAt): string
    {
        if ($this->keyring !== null) {
            return $this->sealVersioned(
                self::REVISION_DOMAIN . $this->revisionMessage($entityTypeId, $entityId, $revisionId, $expiresAt),
            );
        }

        return $this->legacySignRevision($entityTypeId, $entityId, $revisionId, $expiresAt);
    }

    private function legacySign(string $entityTypeId, string $entityId, int $expiresAt): string
    {
        return hash_hmac(
            'sha256',
            $entityTypeId . '|' . $entityId . '|' . $expiresAt,
            $this->requireLegacySecret()->bytes(),
        );
    }

    private function legacySignRevision(string $entityTypeId, string $entityId, int $revisionId, int $expiresAt): string
    {
        return hash_hmac(
            'sha256',
            'revision-v1|' . $entityTypeId . '|' . $entityId . '|' . $revisionId . '|' . $expiresAt,
            $this->requireLegacySecret()->bytes(),
        );
    }

    private function sealVersioned(string $message): string
    {
        $tag = $this->keyring?->authenticate(ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC, $message)
            ?? throw new \LogicException('Publishing preview application-master custody is unavailable.');

        return self::PREFIX . $tag->masterVersion . ':' . self::encode($tag->digestBytes());
    }

    /** @param \Closure(): string $legacySignature */
    private function verifySignature(string $message, string $signature, \Closure $legacySignature): bool
    {
        if (str_starts_with($signature, self::PREFIX)) {
            if ($this->keyring === null
                || preg_match(
                    '/^' . preg_quote(self::PREFIX, '/') . '([1-9][0-9]*):([A-Za-z0-9_-]{43})$/D',
                    $signature,
                    $matches,
                ) !== 1) {
                return false;
            }
            $version = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $digest = self::decode($matches[2]);
            if (!is_int($version)
                || (string) $version !== $matches[1]
                || $digest === null
                || strlen($digest) !== 32) {
                return false;
            }
            try {
                return $this->keyring->verify(
                    ApplicationMasterAuthenticationTag::seal(
                        $version,
                        ApplicationSecret::PURPOSE_PUBLISHING_PREVIEW_HMAC,
                        $digest,
                    ),
                    $message,
                );
            } catch (\Throwable) {
                return false;
            }
        }

        return $this->legacySecret !== null && hash_equals($legacySignature(), $signature);
    }

    private function entityMessage(string $entityTypeId, string $entityId, int $expiresAt): string
    {
        return implode("\0", [$entityTypeId, $entityId, (string) $expiresAt]);
    }

    private function revisionMessage(string $entityTypeId, string $entityId, int $revisionId, int $expiresAt): string
    {
        return implode("\0", [$entityTypeId, $entityId, (string) $revisionId, (string) $expiresAt]);
    }

    private function requireLegacySecret(): SensitiveKey
    {
        return $this->legacySecret
            ?? throw new \LogicException('Legacy publishing preview custody is unavailable.');
    }

    private function assertTtl(int $ttlSeconds): void
    {
        if ($ttlSeconds < 1 || $ttlSeconds > self::DEFAULT_TTL_SECONDS) {
            throw new \InvalidArgumentException(sprintf(
                'Preview TTL must be between 1 and %d seconds.',
                self::DEFAULT_TTL_SECONDS,
            ));
        }
    }

    /** @return array{legacy_secret: string|null, application_master: string|null} */
    public function __debugInfo(): array
    {
        return [
            'legacy_secret' => $this->legacySecret === null ? null : '[REDACTED]',
            'application_master' => $this->keyring === null ? null : '[NON_EXPORTING]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Publishing preview signers cannot be serialized.');
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $encoded): ?string
    {
        $padding = (4 - strlen($encoded) % 4) % 4;
        $bytes = base64_decode(strtr($encoded, '-_', '+/') . str_repeat('=', $padding), true);

        return is_string($bytes) && self::encode($bytes) === $encoded ? $bytes : null;
    }
}
