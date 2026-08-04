<?php

declare(strict_types=1);

namespace Waaseyaa\Auth\Token\Bearer;

/**
 * Non-secret projection of a durable bearer token (#2177 F3).
 *
 * Carries the public identifier, ownership, audience, scopes and lifecycle
 * timestamps — never the secret or its verifier hash. Instances are safe to
 * log, serialize into admin/CLI payloads, and hand to read models.
 *
 * @api
 */
final readonly class BearerTokenRecord
{
    /**
     * @param string $id Non-secret public identifier (`mbt_` + 16 hex).
     * @param int $accountUid The owning account's real uid — the principal
     *        identity every authenticated request resolves through.
     * @param list<string> $scopes Canonical scope list (capability strings).
     * @param string $fingerprint Non-secret 16-hex fingerprint for display.
     * @param ?string $rotatedFrom Predecessor token id when this token was
     *        issued by rotation.
     */
    public function __construct(
        public string $id,
        public int $accountUid,
        public string $audience,
        public array $scopes,
        public string $label,
        public string $fingerprint,
        public \DateTimeImmutable $issuedAt,
        public \DateTimeImmutable $expiresAt,
        public ?\DateTimeImmutable $revokedAt = null,
        public ?string $rotatedFrom = null,
    ) {}

    public function isRevoked(): bool
    {
        return $this->revokedAt !== null;
    }

    /**
     * Inclusive at the boundary: a token is dead AT its expiry instant, the
     * same convention as {@see \Waaseyaa\Foundation\Audit\Approval\ApprovalRequest::isExpiredAt()}.
     */
    public function isExpiredAt(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }
}
