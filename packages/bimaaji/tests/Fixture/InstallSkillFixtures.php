<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Fixture;

use Waaseyaa\Bimaaji\Install\ParsedSkill;

/**
 * Shared fixture set for the M5 WP02 client-transformer tests.
 *
 * Returns three `ParsedSkill` instances that mirror the on-disk fixture
 * skill files at `packages/bimaaji/tests/Fixture/Skills/`. Inlining the
 * skill content avoids a parser dependency the tests don't yet have —
 * the parser ships in M5 WP03.
 *
 * @internal Test scaffolding; not part of the public surface.
 */
final class InstallSkillFixtures
{
    /** @return list<ParsedSkill> */
    public static function all(): array
    {
        return [self::alpha(), self::beta(), self::gamma()];
    }

    public static function alpha(): ParsedSkill
    {
        return new ParsedSkill(
            id: 'skill-alpha',
            name: 'skill-alpha',
            description: 'First fixture skill used by the client-transformer contract tests.',
            frontmatter: [
                'name' => 'skill-alpha',
                'description' => 'First fixture skill used by the client-transformer contract tests.',
            ],
            body: "# Skill Alpha\n\nUse this skill when the framework asks for a basic fixture.\n\nIt has three short paragraphs of body content. The frontmatter above MUST\nbe stripped by the transformer — only this body section is emitted into\nthe per-client target file.\n\nClosing paragraph confirms multi-paragraph bodies survive the transform.",
        );
    }

    public static function beta(): ParsedSkill
    {
        return new ParsedSkill(
            id: 'skill-beta',
            name: 'skill-beta',
            description: 'Second fixture skill — single-paragraph body for size assertions.',
            frontmatter: [
                'name' => 'skill-beta',
                'description' => 'Second fixture skill — single-paragraph body for size assertions.',
            ],
            body: "Single-paragraph body. Used by the size-budget assertions in the\nsingle-file transformer tests.",
        );
    }

    public static function gamma(): ParsedSkill
    {
        return new ParsedSkill(
            id: 'skill-gamma',
            name: 'skill-gamma',
            description: 'Third fixture skill — H2 sub-headings inside the body.',
            frontmatter: [
                'name' => 'skill-gamma',
                'description' => 'Third fixture skill — H2 sub-headings inside the body.',
            ],
            body: "## Subsection one\n\nBody text under the subsection.\n\n## Subsection two\n\nMore body text. The transformer must preserve the inner H2s exactly;\nonly the leading frontmatter block is stripped.",
        );
    }
}
