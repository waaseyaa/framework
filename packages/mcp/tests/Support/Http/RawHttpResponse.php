<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Support\Http;

/**
 * An HTTP response as it arrived on the socket.
 *
 * The point of this value object is that {@see $rawBody} is the exact byte
 * string the server wrote. Assertions in the conformance suite are made against
 * those bytes, or against a re-decode of them — never against a PHP structure
 * that skipped JSON encoding, which is where every defect in #2520 lives.
 */
final readonly class RawHttpResponse
{
    /** @param array<string, list<string>> $headers Lower-cased header names. */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $rawBody,
    ) {}

    public function header(string $name): ?string
    {
        return $this->headers[\strtolower($name)][0] ?? null;
    }

    /**
     * Re-decode preserving JSON object identity: `{}` becomes a \stdClass and
     * `[]` stays a PHP array, so the two are distinguishable — which
     * `json_decode(..., true)` makes impossible.
     */
    public function decodeTyped(): mixed
    {
        return \json_decode($this->rawBody, false, 512, \JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function decodeAssoc(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = \json_decode($this->rawBody, true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
