<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Resource;

use Waaseyaa\AI\Tools\Resource\ContentResourceListResume;
use Waaseyaa\Foundation\Security\ApplicationMasterEnvelope;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationSecret;

/**
 * Purpose-bound AEAD sealing for MCP `resources/list` pagination cursors.
 *
 * Expiry lives in the sealed claims — ApplicationMaster envelopes do not carry
 * an expiry field of their own (#2220/#2636).
 *
 * @api
 */
final class ContentResourceListCursorCodec
{
    public const string PURPOSE = ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR;
    public const int SCHEMA_VERSION = 1;
    public const int DEFAULT_TTL_SECONDS = 900;
    public const string RECORD_IDENTITY = 'mcp-content-resource-list-cursor';

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /** @param ?\Closure(): int $clock */
    public function __construct(
        private readonly ApplicationMasterKeyring $keyring,
        ?\Closure $clock = null,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        if ($ttlSeconds < 1 || $ttlSeconds > 86_400) {
            throw new \InvalidArgumentException('Content resource list cursor TTL must be between 1 and 86400 seconds.');
        }
        $this->now = $clock ?? static fn(): int => time();
    }

    public function seal(ContentResourceListResume $resume, string $principalBinding): string
    {
        $this->assertPrincipalBinding($principalBinding);
        $now = ($this->now)();
        $claims = json_encode([
            'v' => self::SCHEMA_VERSION,
            'exp' => $now + $this->ttlSeconds,
            'p' => $resume->provider,
            't' => $resume->token,
            'b' => $principalBinding,
        ], JSON_THROW_ON_ERROR);

        $envelope = $this->keyring->seal(
            self::PURPOSE,
            self::RECORD_IDENTITY,
            self::SCHEMA_VERSION,
            $claims,
        );

        return self::encodeWire($envelope);
    }

    public function open(string $cursor, string $principalBinding): ContentResourceListResume
    {
        $this->assertPrincipalBinding($principalBinding);
        $invalid = new \InvalidArgumentException('The content resource list cursor is invalid.');
        try {
            $envelope = self::decodeWire($cursor);
            if ($envelope->purpose !== self::PURPOSE
                || $envelope->recordIdentity !== self::RECORD_IDENTITY
                || $envelope->schemaVersion !== self::SCHEMA_VERSION
            ) {
                throw $invalid;
            }
            $plaintext = $this->keyring->open($envelope);
            $claims = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw $invalid;
        }

        if (!is_array($claims)
            || ($claims['v'] ?? null) !== self::SCHEMA_VERSION
            || !is_int($claims['exp'] ?? null)
            || !is_string($claims['p'] ?? null)
            || !is_string($claims['t'] ?? null)
            || !is_string($claims['b'] ?? null)
            || !hash_equals($principalBinding, $claims['b'])
            || $claims['exp'] <= ($this->now)()
        ) {
            throw $invalid;
        }

        try {
            return new ContentResourceListResume($claims['p'], $claims['t']);
        } catch (\InvalidArgumentException) {
            throw $invalid;
        }
    }

    public static function principalBinding(\Waaseyaa\Access\AuthorizationPrincipalInterface $principal): string
    {
        return hash('sha256', 'mcp-content-resource-list|' . (string) $principal->id());
    }

    private function assertPrincipalBinding(string $principalBinding): void
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $principalBinding) !== 1) {
            throw new \InvalidArgumentException('Content resource list principal bindings must be SHA-256 hex digests.');
        }
    }

    private static function encodeWire(ApplicationMasterEnvelope $envelope): string
    {
        $json = json_encode($envelope->toArray(), JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    private static function decodeWire(string $cursor): ApplicationMasterEnvelope
    {
        if ($cursor === '' || strlen($cursor) > 4_096 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
            throw new \InvalidArgumentException('The content resource list cursor is invalid.');
        }
        $json = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', (4 - strlen($cursor) % 4) % 4), true);
        if (!is_string($json)) {
            throw new \InvalidArgumentException('The content resource list cursor is invalid.');
        }
        $document = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($document)) {
            throw new \InvalidArgumentException('The content resource list cursor is invalid.');
        }

        return ApplicationMasterEnvelope::fromArray($document);
    }
}
