<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Locates the canonical Agent Skill set shipped *inside* this package.
 *
 * The skills used to live at the framework monorepo root
 * (`skills/waaseyaa/<id>/SKILL.md`). That path only ever existed in the
 * framework checkout: a consumer installs `waaseyaa/core`/`cms`/`full`,
 * never gets a project-root `skills/` directory, and `bimaaji:install`
 * therefore found nothing and exited 1 (#2656). The skills now ship as
 * package resources, so the same directory resolves identically in the
 * monorepo (`packages/bimaaji/resources/skills`) and in a consumer's
 * vendor tree (`vendor/waaseyaa/bimaaji/resources/skills`).
 *
 * Resolution is anchored on `__DIR__` — the location of the running
 * class file — so it follows the package wherever Composer installs it
 * and never guesses at a project root.
 *
 * @api
 */
final class PackagedSkillResources
{
    /**
     * Relative path from `src/Install/` to the shipped skill set.
     */
    private const string RELATIVE_PATH = '/resources/skills';

    /**
     * Absolute path to the skill resources inside the installed package.
     *
     * Returns the intended path whether or not it exists; existence is
     * the caller's diagnostic to raise (see {@see SkillResourceException})
     * so the message can name the resolved directory.
     */
    public static function directory(): string
    {
        return \dirname(__DIR__, 2) . self::RELATIVE_PATH;
    }
}
