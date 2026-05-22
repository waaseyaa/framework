<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * A single file a {@see ClientTransformerInterface} wants written to a
 * project directory.
 *
 * `path` is relative to the consuming project's root; the install command
 * is responsible for joining it with the project base path and creating
 * any intermediate directories. `content` is the byte payload to write.
 *
 * `sourceSkill` is the originating `ParsedSkill::$id` when the file maps
 * to exactly one skill (e.g. Claude's `.claude/skills/waaseyaa-<id>.md`).
 * For aggregated single-file outputs (e.g. Cursor's `.cursorrules`) it is
 * `null` — the file is a fold of many skills.
 *
 * @api
 */
final readonly class TargetFile
{
    public function __construct(
        public string $path,
        public string $content,
        public ?string $sourceSkill = null,
    ) {}
}
