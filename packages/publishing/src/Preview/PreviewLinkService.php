<?php

declare(strict_types=1);

namespace Waaseyaa\Publishing\Preview;

/**
 * Short-lived signed preview grants for unpublished content.
 *
 * The signature is HMAC-SHA256 over `type|id|expiresAt` with a
 * purpose-derived secret (pass `ApplicationSecret::derive('publishing.preview')`
 * or an app-owned secret — never a raw shared credential). The package ships
 * no HTTP route: the app wires a preview route, verifies the grant, renders
 * the WORKING COPY through its real layout, and MUST mark the response
 * `noindex, nofollow` (header and meta). Verification is constant-time and
 * an expired grant never verifies.
 *
 * @api
 */
final readonly class PreviewLinkService
{
    private const int DEFAULT_TTL_SECONDS = 1800;

    /** @var \Closure(): int Injectable clock for tests; defaults to time(). */
    private \Closure $clock;

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        #[\SensitiveParameter]
        private string $secret,
        ?\Closure $clock = null,
    ) {
        if ($this->secret === '') {
            throw new \InvalidArgumentException('Preview secret must not be empty.');
        }
        $this->clock = $clock ?? static fn(): int => time();
    }

    public function issue(string $entityTypeId, string $entityId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): PreviewToken
    {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Preview TTL must be positive.');
        }
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

        return hash_equals($this->sign($entityTypeId, $entityId, $expiresAt), $signature);
    }

    private function sign(string $entityTypeId, string $entityId, int $expiresAt): string
    {
        return hash_hmac('sha256', $entityTypeId . '|' . $entityId . '|' . $expiresAt, $this->secret);
    }
}
