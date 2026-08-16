<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the schema-v2 identity contract for every recorded S1 roster
 * (docs/specs/governed-gates.md §6): entries bind semantic identity
 * (path, pattern, class, normalized match hash, occurrence) and never
 * positional data (line, line_sha256, source_sha256), so unrelated edits in
 * rostered files cannot invalidate a roster.
 */
#[CoversNothing]
final class S1RosterSchemaV2Test extends TestCase
{
    private const ROSTERS = [
        'support/s1-configuration-activation-roster.json',
        'support/s1-configuration-authority-roster.json',
        'support/s1-schema-authority-roster.json',
        'support/s1-sqlite-construction-roster.json',
    ];

    private const IDENTITY_KEYS = ['path', 'pattern', 'class', 'match_sha256', 'occurrence'];

    #[Test]
    public function every_recorded_roster_uses_semantic_identity_schema_v2(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (self::ROSTERS as $relative) {
            $roster = json_decode((string) file_get_contents($root . '/' . $relative), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(2, $roster['schema_version'] ?? null, $relative . ' must be schema version 2.');
            $this->assertIsArray($roster['candidates'] ?? null, $relative . ' must carry a candidates list.');

            foreach ($roster['candidates'] as $index => $candidate) {
                $this->assertSame(
                    self::IDENTITY_KEYS,
                    array_keys($candidate),
                    sprintf('%s candidate #%d must bind exactly the semantic identity keys — no line/line_sha256/source_sha256.', $relative, $index),
                );
            }
        }
    }
}
