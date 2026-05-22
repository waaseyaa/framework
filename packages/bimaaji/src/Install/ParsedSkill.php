<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Parsed representation of a single `skills/waaseyaa/<id>/SKILL.md` document.
 *
 * Produced by the (forthcoming) skill-set parser and handed to each
 * {@see ClientTransformerInterface} so a client transformer can decide how
 * to surface the skill in its own config-file format. Plain value object —
 * no behaviour, just labelled fields. Construct via the named-argument
 * form to keep callsites self-documenting.
 *
 * @api
 */
final readonly class ParsedSkill
{
    /**
     * @param string $id Stable skill identifier (kebab-case directory name, e.g. `entity-system`).
     * @param string $name Human-readable skill name from the YAML frontmatter.
     * @param string $description One-line description from the frontmatter.
     * @param array<string, mixed> $frontmatter Full parsed frontmatter; consumers MAY pluck additional keys but should not assume any beyond `name`/`description`.
     * @param string $body Skill body with the YAML frontmatter block stripped.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
        public array $frontmatter,
        public string $body,
    ) {}
}
