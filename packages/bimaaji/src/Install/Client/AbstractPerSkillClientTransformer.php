<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

use Waaseyaa\Bimaaji\Install\ClientCapabilities;
use Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry;
use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Install\ManagedRegion;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\SkillInventory;
use Waaseyaa\Bimaaji\Install\TargetFile;

/**
 * Shared base for clients that receive concise, always-loaded guidance plus
 * one on-demand skill file per canonical inventory entry (Claude Code and
 * Codex — #2660 Part B).
 *
 * There is exactly one renderer for this shape, not one copy per client:
 * before this class, `ClaudeClientTransformer` carried its own per-skill
 * and index rendering, and giving Codex the same shape would have meant a
 * second copy that could quietly drift from the first. Subclasses supply
 * only `clientId()` and `guidanceTitle()`; every byte of output is derived
 * from the shared renderer plus the resolved {@see ClientCapabilities}
 * entry, exactly as {@see AbstractSingleFileClientTransformer} already does
 * for the six single-file clients.
 *
 * Every per-skill file carries a one-line HTML-comment provenance footer
 * naming the sha256 of the **whole inventory** it was rendered from, and
 * the guidance index lists each skill's own **source** sha256 next to its
 * target path. Together these let a caller prove Claude and Codex
 * regenerated their per-skill output from the same canonical
 * `resources/skills/` set, and that the two clients' per-skill bytes are
 * identical for the same inventory (see `CodexClientTransformerTest`).
 *
 * @api
 */
abstract class AbstractPerSkillClientTransformer implements ClientTransformerInterface
{
    /**
     * @param ClientCapabilities|null $capabilities Overrides the registered
     *     entry. Null — the production path — resolves
     *     {@see ClientCapabilityRegistry::default()}. A non-null value must
     *     declare this client id; anything else is a programming error.
     */
    public function __construct(private readonly ?ClientCapabilities $capabilities = null) {}

    /**
     * @param list<ParsedSkill> $skills
     * @return list<TargetFile>
     */
    public function targetFiles(array $skills): array
    {
        $capabilities = $this->resolveCapabilities();
        $inventory = SkillInventory::fromSkills($skills);
        $files = [];

        foreach ($skills as $skill) {
            $files[] = new TargetFile(
                path: $capabilities->skillFilePath($skill->id),
                content: $this->renderSkillFile($skill, $capabilities, $inventory),
                sourceSkill: $skill->id,
            );
        }

        $files[] = new TargetFile(
            path: $capabilities->guidancePath,
            content: $this->renderGuidance($skills, $capabilities, $inventory),
            sourceSkill: null,
        );

        return $files;
    }

    /**
     * This subclass's guidance file title (e.g. "# Waaseyaa framework —
     * Claude Code guidelines"). The only per-client rendering decision this
     * class does not make for its subclasses.
     */
    abstract protected function guidanceTitle(): string;

    /**
     * The capabilities this render reads — the injected override, else this
     * client's registered entry. A mismatched injected client id is a
     * programming error and fails loudly rather than silently rendering the
     * wrong client's conventions.
     */
    private function resolveCapabilities(): ClientCapabilities
    {
        if ($this->capabilities !== null) {
            if ($this->capabilities->clientId !== $this->clientId()) {
                throw new \LogicException(sprintf(
                    '%s was given capabilities that declare client "%s", not "%s".',
                    static::class,
                    $this->capabilities->clientId,
                    $this->clientId(),
                ));
            }

            return $this->capabilities;
        }

        $capabilities = ClientCapabilityRegistry::default()->for($this->clientId());
        if ($capabilities === null) {
            throw new \LogicException(sprintf(
                'No registered ClientCapabilityRegistry entry for client "%s".',
                $this->clientId(),
            ));
        }

        return $capabilities;
    }

    private function renderSkillFile(
        ParsedSkill $skill,
        ClientCapabilities $capabilities,
        SkillInventory $inventory,
    ): string {
        $body = ManagedRegion::wrap(trim($skill->body) . "\n\n" . $this->provenanceFooter($inventory));

        // Frontmatter is a discovery requirement of the client, so the
        // capability decides — not this method.
        if (!$capabilities->requiresFrontmatterAtByteZero) {
            return $body;
        }

        return sprintf(
            "---\nname: %s\ndescription: %s\n---\n\n%s",
            $capabilities->skillDirectoryName($skill->id),
            $this->escapeYamlScalar($skill->description),
            $body,
        );
    }

    /**
     * @param list<ParsedSkill> $skills
     */
    private function renderGuidance(
        array $skills,
        ClientCapabilities $capabilities,
        SkillInventory $inventory,
    ): string {
        $lines = [
            $this->guidanceTitle(),
            '',
            'Auto-generated by `bin/waaseyaa bimaaji:install`. Re-run the install command to refresh.',
            'Edits outside the markers below are preserved across re-runs.',
            '',
            'This file stays concise; detailed skill bodies load on demand from the per-skill files listed below.',
            '',
            '## Available skills',
            '',
        ];

        if ($skills === []) {
            $lines[] = '_No skills installed._';
        } else {
            foreach ($skills as $skill) {
                $lines[] = sprintf(
                    '- **%s** — %s — `%s` (source sha256 `%s`)',
                    $skill->name,
                    $skill->description,
                    $capabilities->skillFilePath($skill->id),
                    $skill->sourceSha256,
                );
            }
        }

        $lines[] = '';
        $lines[] = sprintf('Inventory sha256: `%s`', $inventory->inventorySha256());

        return ManagedRegion::wrap(implode("\n", $lines));
    }

    private function provenanceFooter(SkillInventory $inventory): string
    {
        return sprintf('<!-- waaseyaa:bimaaji:source-inventory sha256=%s -->', $inventory->inventorySha256());
    }

    private function escapeYamlScalar(string $value): string
    {
        if (str_contains($value, ':') || str_contains($value, '#') || str_contains($value, '"')) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return $value;
    }
}
