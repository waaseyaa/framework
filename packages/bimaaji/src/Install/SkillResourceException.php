<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Raised when the canonical Agent Skill resources cannot be read.
 *
 * Two failure classes, deliberately kept apart because the operator
 * remedy differs:
 *
 * - **Missing** — the resource directory does not exist, is not readable,
 *   or contains no `<id>/SKILL.md`. Almost always an incomplete or
 *   pruned install of `waaseyaa/bimaaji` (a `--no-dev` archive with an
 *   over-eager `export-ignore`, a partially synced deploy directory), or
 *   a `bimaaji.skills_directory` override pointing at the wrong place.
 *   Remedy: reinstall the package, or fix the override.
 * - **Corrupt** — a `SKILL.md` exists but cannot be parsed (unterminated
 *   YAML frontmatter, unreadable bytes, empty document). Remedy: repair
 *   or restore that one file; the diagnostic names it.
 *
 * Every message names the *resolved absolute directory* rather than a
 * repository-relative path, because the two differ for a packaged
 * consumer (`vendor/waaseyaa/bimaaji/resources/skills`) and the old
 * monorepo-shaped wording sent consumers looking for a `skills/`
 * directory their project never had.
 *
 * @api
 */
final class SkillResourceException extends \RuntimeException
{
    private function __construct(
        string $message,
        public readonly string $directory,
        public readonly SkillResourceFailure $failure,
        public readonly ?string $skillFile = null,
    ) {
        parent::__construct($message);
    }

    public static function missingDirectory(string $directory, bool $configured): self
    {
        return new self(
            sprintf(
                'the Agent Skill resource directory %s does not exist. %s',
                $directory,
                self::remedy($configured),
            ),
            $directory,
            SkillResourceFailure::Missing,
        );
    }

    public static function unreadableDirectory(string $directory, bool $configured): self
    {
        return new self(
            sprintf(
                'the Agent Skill resource directory %s exists but could not be read (check filesystem permissions). %s',
                $directory,
                self::remedy($configured),
            ),
            $directory,
            SkillResourceFailure::Missing,
        );
    }

    public static function emptyDirectory(string $directory, bool $configured): self
    {
        return new self(
            sprintf(
                'the Agent Skill resource directory %s contains no <skill-id>/SKILL.md documents. %s',
                $directory,
                self::remedy($configured),
            ),
            $directory,
            SkillResourceFailure::Missing,
        );
    }

    public static function corruptSkill(string $directory, string $file, string $reason): self
    {
        return new self(
            sprintf(
                'the Agent Skill document %s is corrupt (%s). Restore or repair that single file; '
                . 'reinstalling waaseyaa/bimaaji replaces the shipped copy. All other skills in %s were readable.',
                $file,
                $reason,
                $directory,
            ),
            $directory,
            SkillResourceFailure::Corrupt,
            $file,
        );
    }

    private static function remedy(bool $configured): string
    {
        if ($configured) {
            return 'This path came from the `bimaaji.skills_directory` configuration override — point it at a directory '
                . 'of <skill-id>/SKILL.md documents, or remove the override to fall back to the skills shipped inside '
                . 'the installed waaseyaa/bimaaji package.';
        }

        return 'This is the resource directory shipped inside the installed waaseyaa/bimaaji package. '
            . 'Reinstall it (`composer reinstall waaseyaa/bimaaji`) or set `bimaaji.skills_directory` in your '
            . 'application configuration to a directory of <skill-id>/SKILL.md documents.';
    }
}
