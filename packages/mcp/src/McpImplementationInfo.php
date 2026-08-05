<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp;

/**
 * One implementation identity projected by every MCP discovery surface.
 *
 * `version` is informational implementation provenance. MCP compatibility is
 * negotiated independently through {@see McpProtocol}.
 *
 * @api
 */
final readonly class McpImplementationInfo
{
    public function __construct(
        public string $name,
        public string $version,
    ) {
        self::assertText($this->name, 'name', 100);
        self::assertText($this->version, 'version', 255);
        if ($this->version === 'latest'
            || \preg_match('/^(?:[~^]|[<>]=?)|(?:^|[.])(?:x|X|\*)$/', $this->version) === 1
        ) {
            throw new \InvalidArgumentException('MCP implementation version must identify one implementation, not a version range.');
        }
    }

    /** @return array{name: string, version: string} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'version' => $this->version];
    }

    private static function assertText(string $value, string $field, int $maxBytes): void
    {
        if ($value === ''
            || $value !== \trim($value)
            || \strlen($value) > $maxBytes
            || \preg_match('//u', $value) !== 1
            || \preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            throw new \InvalidArgumentException('MCP implementation ' . $field . ' must be non-empty, bounded UTF-8 text without control characters.');
        }
    }
}
