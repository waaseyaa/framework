<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Concurrency;

/**
 * Opaque, identity-bound expectation for one persisted aggregate snapshot.
 *
 * The random tag is the unguessable authority. The selectors make accidental
 * transplantation fail before storage access and make the serialized form
 * self-describing without requiring a signing key.
 *
 * @api
 */
final readonly class EntityMutationToken
{
    private const string PREFIX = 'emt1.';

    private function __construct(
        public string $storageAuthority,
        public string $tenantId,
        public string $entityTypeId,
        public string $entityId,
        public int $aggregateVersion,
        private string $tag,
    ) {}

    public static function issue(
        string $storageAuthority,
        string $tenantId,
        string $entityTypeId,
        string $entityId,
        int $aggregateVersion,
        ?string $tag = null,
    ): self {
        foreach ([
            'storage authority' => $storageAuthority,
            'tenant id' => $tenantId,
            'entity type id' => $entityTypeId,
            'entity id' => $entityId,
        ] as $name => $value) {
            if ($value === '') {
                throw new \InvalidArgumentException("Entity mutation token {$name} must not be empty.");
            }
        }
        if ($aggregateVersion < 1) {
            throw new \InvalidArgumentException('Entity mutation token version must be positive.');
        }
        $tag ??= random_bytes(32);
        if (strlen($tag) !== 32) {
            throw new \InvalidArgumentException('Entity mutation token tag must contain exactly 32 bytes.');
        }

        return new self($storageAuthority, $tenantId, $entityTypeId, $entityId, $aggregateVersion, $tag);
    }

    public static function fromOpaqueString(string $encoded): self
    {
        if (!str_starts_with($encoded, self::PREFIX)) {
            throw new \InvalidArgumentException('Unsupported entity mutation token.');
        }
        $payload = substr($encoded, strlen(self::PREFIX));
        if ($payload === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $payload) !== 1) {
            throw new \InvalidArgumentException('Malformed entity mutation token.');
        }
        $padding = (4 - strlen($payload) % 4) % 4;
        $json = base64_decode(strtr($payload . str_repeat('=', $padding), '-_', '+/'), true);
        if ($json === false) {
            throw new \InvalidArgumentException('Malformed entity mutation token.');
        }

        try {
            $values = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \InvalidArgumentException('Malformed entity mutation token.', previous: $exception);
        }
        if (!is_array($values) || array_keys($values) !== ['s', 't', 'y', 'i', 'v', 'g']) {
            throw new \InvalidArgumentException('Malformed entity mutation token payload.');
        }
        if (!is_string($values['s']) || !is_string($values['t']) || !is_string($values['y'])
            || !is_string($values['i']) || !is_int($values['v']) || !is_string($values['g'])
        ) {
            throw new \InvalidArgumentException('Malformed entity mutation token fields.');
        }
        $tag = hex2bin($values['g']);
        if ($tag === false) {
            throw new \InvalidArgumentException('Malformed entity mutation token tag.');
        }
        $token = self::issue($values['s'], $values['t'], $values['y'], $values['i'], $values['v'], $tag);
        if (!hash_equals($token->toOpaqueString(), $encoded)) {
            throw new \InvalidArgumentException('Non-canonical entity mutation token.');
        }

        return $token;
    }

    public static function fromHttpIfMatch(string $header): self
    {
        if (strlen($header) < 3 || $header[0] !== '"' || $header[strlen($header) - 1] !== '"'
            || str_starts_with($header, 'W/') || str_contains($header, ',') || $header === '"*"'
        ) {
            throw new \InvalidArgumentException('If-Match must contain exactly one strong entity mutation ETag.');
        }

        return self::fromOpaqueString(substr($header, 1, -1));
    }

    public function toOpaqueString(): string
    {
        $json = json_encode([
            's' => $this->storageAuthority,
            't' => $this->tenantId,
            'y' => $this->entityTypeId,
            'i' => $this->entityId,
            'v' => $this->aggregateVersion,
            'g' => bin2hex($this->tag),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        return self::PREFIX . rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public function toStrongEtag(): string
    {
        return '"' . $this->toOpaqueString() . '"';
    }

    /** @internal Mutation authority comparison only. */
    public function tagHex(): string
    {
        return bin2hex($this->tag);
    }
}
