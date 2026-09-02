<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * What one agent client accepts from `bimaaji:install`, as data rather
 * than behaviour encoded across a transformer class hierarchy.
 *
 * Instances describe the **currently shipped** convention for a client —
 * see {@see ClientCapabilityRegistry::default()} — not an aspirational
 * target. A client transformer reads its own `ClientCapabilities` to
 * derive the paths it writes instead of hardcoding them, so the mapping
 * lives in exactly one place.
 *
 * `guidancePath` is the client's concise, always-loaded surface: for a
 * {@see SkillDeliveryMode::SingleConsolidatedFile} client that is the one
 * file everything folds into (`AGENTS.md`, `.cursorrules`, …); for a
 * {@see SkillDeliveryMode::PerSkillFile} client (Claude Code today) it is
 * the separate consolidated index file the transformer writes alongside
 * the per-skill directories.
 *
 * @api
 */
final readonly class ClientCapabilities
{
    /**
     * @param string $clientId Matches {@see ClientTransformerInterface::clientId()}.
     * @param SkillDeliveryMode $skillDelivery How this client wants the skill set delivered.
     * @param bool $requiresFrontmatterAtByteZero Whether a per-skill file MUST open with a YAML frontmatter block at byte 0 for the client to discover it (true for Claude Code; meaningless — always false — for a `SingleConsolidatedFile` client, which never emits per-skill frontmatter).
     * @param string $guidancePath Project-relative path to the client's concise/consolidated guidance file.
     * @param string|null $skillDirectory Project-relative base directory for per-skill files. Required when `$skillDelivery` is `PerSkillFile`, null otherwise.
     * @param string|null $skillIdPrefix Prefix applied to a skill id when building its per-skill directory/file name (e.g. `waaseyaa-`), so an installed skill is visibly framework-owned and does not collide with a same-named project skill. Only meaningful alongside `$skillDirectory`.
     */
    public function __construct(
        public string $clientId,
        public SkillDeliveryMode $skillDelivery,
        public bool $requiresFrontmatterAtByteZero,
        public string $guidancePath,
        public ?string $skillDirectory = null,
        public ?string $skillIdPrefix = null,
    ) {}

    /**
     * The per-skill directory name for one skill id, prefix applied.
     *
     * This is also the Claude "command name" (`/waaseyaa-<id>`) and the
     * frontmatter `name` re-emitted into the per-skill file, so callers
     * needing either must go through this single derivation.
     */
    public function skillDirectoryName(string $skillId): string
    {
        return ($this->skillIdPrefix ?? '') . $skillId;
    }

    /**
     * Project-relative path to one skill's per-skill file.
     *
     * @throws \LogicException When this client does not declare `PerSkillFile` delivery.
     */
    public function skillFilePath(string $skillId): string
    {
        if ($this->skillDelivery !== SkillDeliveryMode::PerSkillFile || $this->skillDirectory === null) {
            throw new \LogicException(sprintf(
                'Client "%s" does not support per-skill file delivery (skillDelivery=%s).',
                $this->clientId,
                $this->skillDelivery->name,
            ));
        }

        return sprintf('%s/%s/SKILL.md', $this->skillDirectory, $this->skillDirectoryName($skillId));
    }
}
