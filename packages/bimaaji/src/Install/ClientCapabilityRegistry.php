<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * The single data-driven source of what each supported client accepts.
 *
 * Before this registry, "single consolidated file vs per-skill files",
 * "does this client need YAML frontmatter", and "which path does this
 * client read" were three separate facts encoded as PHP control flow
 * spread across seven transformer classes (an abstract single-file base
 * plus per-client `targetPath()` overrides, and a hand-rolled Claude
 * transformer duplicating the same shape with its own constant). This
 * registry names those three facts as data once, per client, and the
 * transformers under `Install/Client/` read it instead of hardcoding it.
 *
 * `default()` mirrors the **currently shipped** convention for all seven
 * launch clients — see the "Supported clients" table in
 * `docs/specs/bimaaji-install.md` — and changing an entry here changes
 * what `bimaaji:install` writes to disk. It intentionally does NOT encode
 * the #2660 decision memo's proposed conventions (e.g. Codex per-skill
 * `.agents/skills/`), because that mapping is gated on an undecided
 * maintainer question (open question (a) — see
 * `docs/adr/025-client-guidance-and-skill-conventions.md`).
 *
 * @api
 */
final class ClientCapabilityRegistry
{
    /** @var array<string, ClientCapabilities> clientId => capabilities, sorted by clientId */
    private readonly array $byClientId;

    /**
     * @param list<ClientCapabilities> $capabilities
     */
    private function __construct(array $capabilities)
    {
        $map = [];
        foreach ($capabilities as $entry) {
            $map[$entry->clientId] = $entry;
        }
        ksort($map);
        $this->byClientId = $map;
    }

    /**
     * The registry for the seven launch clients, matching what their
     * transformers write today (verified against upstream vendor docs
     * 2026-08-29 — see `docs/specs/bimaaji-install.md` "Supported clients").
     */
    public static function default(): self
    {
        return new self([
            new ClientCapabilities(
                clientId: 'claude',
                skillDelivery: SkillDeliveryMode::PerSkillFile,
                requiresFrontmatterAtByteZero: true,
                guidancePath: '.claude/CLAUDE-WAASEYAA.md',
                skillDirectory: '.claude/skills',
                skillIdPrefix: 'waaseyaa-',
            ),
            new ClientCapabilities(
                clientId: 'codex',
                skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
                requiresFrontmatterAtByteZero: false,
                guidancePath: 'AGENTS.md',
            ),
            new ClientCapabilities(
                clientId: 'copilot',
                skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
                requiresFrontmatterAtByteZero: false,
                guidancePath: '.github/copilot-instructions.md',
            ),
            new ClientCapabilities(
                clientId: 'cursor',
                skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
                requiresFrontmatterAtByteZero: false,
                guidancePath: '.cursorrules',
            ),
            new ClientCapabilities(
                clientId: 'gemini',
                skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
                requiresFrontmatterAtByteZero: false,
                guidancePath: 'GEMINI.md',
            ),
            new ClientCapabilities(
                clientId: 'junie',
                skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
                requiresFrontmatterAtByteZero: false,
                guidancePath: '.junie/guidelines.md',
            ),
            new ClientCapabilities(
                clientId: 'windsurf',
                skillDelivery: SkillDeliveryMode::SingleConsolidatedFile,
                requiresFrontmatterAtByteZero: false,
                guidancePath: '.windsurfrules',
            ),
        ]);
    }

    /**
     * Capabilities for one client, or null when the id is not registered.
     */
    public function for(string $clientId): ?ClientCapabilities
    {
        return $this->byClientId[$clientId] ?? null;
    }

    /**
     * @return list<ClientCapabilities> sorted by clientId
     */
    public function all(): array
    {
        return array_values($this->byClientId);
    }

    /**
     * @return list<string> sorted client ids
     */
    public function clientIds(): array
    {
        return array_keys($this->byClientId);
    }
}
