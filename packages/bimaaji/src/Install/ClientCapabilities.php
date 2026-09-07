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
 * {@see SkillDeliveryMode::PerSkillFile} client (Claude Code and Codex) it is
 * the separate consolidated index file the transformer writes alongside
 * the per-skill directories.
 *
 * The shape is **closed at construction** (see
 * {@see ClientCapabilityException}). Ids and paths may not be empty, and
 * the two delivery modes admit disjoint field sets: a
 * `SingleConsolidatedFile` client carries no `skillDirectory`, no
 * `skillIdPrefix`, and never requires frontmatter, because it emits no
 * per-skill file for any of those to govern; a `PerSkillFile` client must
 * name the directory it writes into. These fields drive output — the paths
 * every transformer writes, and whether
 * {@see Client\AbstractPerSkillClientTransformer} opens a per-skill file with
 * YAML frontmatter — so an unenforced contradiction is a silently wrong install,
 * not a harmless field.
 *
 * @api
 */
final readonly class ClientCapabilities
{
    /**
     * @param string $clientId Matches {@see ClientTransformerInterface::clientId()}.
     * @param SkillDeliveryMode $skillDelivery How this client wants the skill set delivered.
     * @param bool $requiresFrontmatterAtByteZero Whether a per-skill file MUST open with a YAML frontmatter block at byte 0 for the client to discover it (true for Claude Code and Codex). {@see Client\AbstractPerSkillClientTransformer} derives its per-skill rendering from this flag, so it is load-bearing, not documentation. Rejected as a contradiction for a `SingleConsolidatedFile` client, which emits no per-skill file for it to govern.
     * @param string $guidancePath Project-relative path to the client's concise/consolidated guidance file.
     * @param string|null $skillDirectory Project-relative base directory for per-skill files. Required when `$skillDelivery` is `PerSkillFile`, null otherwise.
     * @param string|null $skillIdPrefix Prefix applied to a skill id when building its per-skill directory/file name (e.g. `waaseyaa-`), so an installed skill is visibly framework-owned and does not collide with a same-named project skill. Only meaningful alongside `$skillDirectory`; `null` means no prefix, and `''` is rejected.
     */
    public function __construct(
        public string $clientId,
        public SkillDeliveryMode $skillDelivery,
        public bool $requiresFrontmatterAtByteZero,
        public string $guidancePath,
        public ?string $skillDirectory = null,
        public ?string $skillIdPrefix = null,
    ) {
        if (trim($clientId) === '') {
            throw ClientCapabilityException::blankField($clientId, 'clientId');
        }

        if (trim($guidancePath) === '') {
            throw ClientCapabilityException::blankField($clientId, 'guidancePath');
        }

        if ($skillDirectory !== null && trim($skillDirectory) === '') {
            throw ClientCapabilityException::blankField($clientId, 'skillDirectory');
        }

        // An empty prefix is not "no prefix" — it is a caller that meant to
        // set one and produced a no-op. `null` is how you say "no prefix".
        if ($skillIdPrefix !== null && trim($skillIdPrefix) === '') {
            throw ClientCapabilityException::blankField($clientId, 'skillIdPrefix');
        }

        if ($skillDelivery === SkillDeliveryMode::PerSkillFile) {
            if ($skillDirectory === null) {
                throw ClientCapabilityException::perSkillWithoutSkillDirectory($clientId);
            }

            return;
        }

        if ($skillDirectory !== null) {
            throw ClientCapabilityException::consolidatedCarriesPerSkillField($clientId, 'skillDirectory');
        }

        if ($requiresFrontmatterAtByteZero) {
            throw ClientCapabilityException::consolidatedRequiresFrontmatter($clientId);
        }

        if ($skillIdPrefix !== null) {
            throw ClientCapabilityException::consolidatedCarriesPerSkillField($clientId, 'skillIdPrefix');
        }
    }

    /**
     * The per-skill directory name for one skill id, prefix applied.
     *
     * This is also the client-visible skill name (`waaseyaa-<id>`) and the
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
     * @throws \LogicException When this client does not declare `PerSkillFile` delivery. The
     *     null-`skillDirectory` half of the guard is unreachable for a constructed instance
     *     (the constructor closes that shape); it stays so this never composes an empty
     *     leading path segment if the invariant is ever relaxed.
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

    /**
     * Which {@see ClientCapabilitySurface} values this client's shipped
     * convention can represent. `Guidelines` always; `Skills` only for a
     * `PerSkillFile` client. Read by {@see ClientCapabilityDiagnostics} —
     * derives from `$skillDelivery` rather than adding a parallel field
     * that could disagree with it.
     *
     * @return list<ClientCapabilitySurface>
     */
    public function supportedSurfaces(): array
    {
        $surfaces = [ClientCapabilitySurface::Guidelines];

        if ($this->skillDelivery === SkillDeliveryMode::PerSkillFile) {
            $surfaces[] = ClientCapabilitySurface::Skills;
        }

        return $surfaces;
    }
}
