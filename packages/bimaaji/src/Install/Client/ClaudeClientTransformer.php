<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install\Client;

use Waaseyaa\Bimaaji\Install\ClientCapabilities;
use Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry;
use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Install\ManagedRegion;
use Waaseyaa\Bimaaji\Install\ParsedSkill;
use Waaseyaa\Bimaaji\Install\TargetFile;

/**
 * Multi-file transformer for Claude Code.
 *
 * A Claude Code **project skill is a directory**, not a file:
 * `.claude/skills/<skill-name>/SKILL.md`. The command a user types comes from
 * the *directory* name; the frontmatter `name` is only the display label shown
 * in skill listings. A flat `.claude/skills/<name>.md` is not a documented
 * layout and is not discovered at all — which is what this transformer emitted
 * until #2656 follow-up review, so every "written" count it reported was
 * producing files Claude Code would never load.
 *
 * We therefore emit one directory per skill
 * (`.claude/skills/waaseyaa-<id>/SKILL.md`) plus one consolidated guidelines
 * page (`.claude/CLAUDE-WAASEYAA.md`) that lists each skill — symmetric with
 * the other clients' single-file output for users who prefer the full set.
 * The directory-per-skill shape also matches the way the canonical set ships
 * inside this package (`resources/skills/<id>/SKILL.md`), so the install is a
 * structural rename rather than a flattening.
 *
 * Both outputs are marker-bounded (see {@see ManagedRegion}). For a per-skill
 * file the markers open *after* the YAML frontmatter, because Claude Code
 * reads frontmatter "only when the opening `---` is the file's first line" —
 * so the skill body refreshes on every run while a consumer's own
 * `name`/`description` edits, and any notes they append below the closing
 * marker, survive. A skill directory may also hold supporting files the user
 * added; the installer never removes those.
 *
 * That frontmatter block is emitted **because
 * {@see ClientCapabilities::$requiresFrontmatterAtByteZero} says so**, not
 * because this class hardcodes it. Path, prefix and frontmatter all come from
 * the one registry entry, so the registry cannot describe a client whose
 * shipped bytes disagree with it. Injecting capabilities via the constructor
 * is a test seam for exactly that proof; production always resolves the
 * registered entry.
 *
 * Upstream convention: <https://code.claude.com/docs/en/skills> §"Where skills
 * live" (`Project | .claude/skills/<skill-name>/SKILL.md`) and §"How a skill
 * gets its command name" (verified 2026-08-29).
 *
 * @api
 */
final class ClaudeClientTransformer implements ClientTransformerInterface
{
    /**
     * @param ClientCapabilities|null $capabilities Overrides the registered
     *     entry. Null — the production path — resolves
     *     {@see ClientCapabilityRegistry::default()}. A non-null value must
     *     declare this client id; anything else is a programming error.
     */
    public function __construct(private readonly ?ClientCapabilities $capabilities = null) {}

    public function clientId(): string
    {
        return 'claude';
    }

    public function targetFiles(array $skills): array
    {
        $capabilities = $this->resolveCapabilities();
        $files = [];

        foreach ($skills as $skill) {
            $files[] = new TargetFile(
                path: $capabilities->skillFilePath($skill->id),
                content: $this->renderSkillFile($skill, $capabilities),
                sourceSkill: $skill->id,
            );
        }

        $files[] = new TargetFile(
            path: $capabilities->guidancePath,
            content: $this->renderIndex($skills, $capabilities),
            sourceSkill: null,
        );

        return $files;
    }

    /**
     * The capabilities this render reads — the injected override, else this
     * client's registered entry. See the
     * {@see AbstractSingleFileClientTransformer::capabilities()} docblock
     * for why a missing entry is a `\LogicException`, not a soft failure;
     * an override for a *different* client is the same class of error, and
     * is rejected rather than quietly used to render Claude's output.
     */
    private function resolveCapabilities(): ClientCapabilities
    {
        if ($this->capabilities !== null) {
            if ($this->capabilities->clientId !== $this->clientId()) {
                throw new \LogicException(sprintf(
                    'ClaudeClientTransformer was given capabilities that declare client "%s", not "%s".',
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

    private function renderSkillFile(ParsedSkill $skill, ClientCapabilities $capabilities): string
    {
        $body = ManagedRegion::wrap($skill->body);

        // Frontmatter is a discovery requirement of the client, so the
        // capability decides — not this method. A per-skill client that does
        // not require it gets the managed body alone.
        if (!$capabilities->requiresFrontmatterAtByteZero) {
            return $body;
        }

        // `name` must match the directory so the listing label and the
        // command a user types agree; `description` is what Claude reads to
        // decide whether to load the skill.
        return sprintf(
            "---\nname: %s\ndescription: %s\n---\n\n%s",
            $capabilities->skillDirectoryName($skill->id),
            $this->escapeYamlScalar($skill->description),
            $body,
        );
    }

    /** @param list<ParsedSkill> $skills */
    private function renderIndex(array $skills, ClientCapabilities $capabilities): string
    {
        $lines = [
            '# Waaseyaa framework — Claude Code guidelines',
            '',
            'Auto-generated by `bin/waaseyaa bimaaji:install`. Re-run the install command to refresh.',
            'Edits outside the markers below are preserved across re-runs.',
            '',
            '## Available skills',
            '',
        ];

        if ($skills === []) {
            $lines[] = '_No skills installed._';
        } else {
            foreach ($skills as $skill) {
                $lines[] = sprintf(
                    '- `/%s` — **%s** — %s',
                    $capabilities->skillDirectoryName($skill->id),
                    $skill->name,
                    $skill->description,
                );
            }
        }

        return ManagedRegion::wrap(implode("\n", $lines));
    }

    private function escapeYamlScalar(string $value): string
    {
        if (str_contains($value, ':') || str_contains($value, '#') || str_contains($value, '"')) {
            return "'" . str_replace("'", "''", $value) . "'";
        }

        return $value;
    }
}
