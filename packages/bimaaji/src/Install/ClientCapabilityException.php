<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Install;

/**
 * Raised when a {@see ClientCapabilities} instance would describe a client
 * shape `bimaaji:install` cannot honour.
 *
 * `ClientCapabilities` is an `@api` value object whose fields now *drive*
 * output — the paths every transformer writes, and (since the #2660 Part A
 * repair round) whether {@see Client\ClaudeClientTransformer} opens a
 * per-skill file with YAML frontmatter. A nonsensical instance is therefore
 * not inert data: it is a silently wrong install, discovered by a consumer
 * whose skills stopped loading. Every invariant is checked in the
 * constructor so the failure names the offending literal instead.
 *
 * This is an `\InvalidArgumentException`, not a `\RuntimeException`: an
 * out-of-set capability is always a programming error at a construction
 * site the maintainer controls (the registry, a test, a downstream
 * extension), never a reachable runtime state driven by consumer data.
 *
 * @api
 */
final class ClientCapabilityException extends \InvalidArgumentException
{
    private function __construct(string $message, public readonly string $clientId)
    {
        parent::__construct($message);
    }

    /**
     * A required string field was empty or whitespace-only.
     *
     * `$field` is the constructor parameter name so the message points at
     * the argument to fix.
     */
    public static function blankField(string $clientId, string $field): self
    {
        return new self(
            sprintf(
                'ClientCapabilities for client "%s": %s must be a non-empty string.',
                $clientId,
                $field,
            ),
            $clientId,
        );
    }

    /**
     * A {@see SkillDeliveryMode::PerSkillFile} client declared no
     * `skillDirectory` — there is nowhere to write its per-skill files.
     */
    public static function perSkillWithoutSkillDirectory(string $clientId): self
    {
        return new self(
            sprintf(
                'ClientCapabilities for client "%s" declares PerSkillFile delivery but declares no skillDirectory; '
                . 'there is no base directory to write per-skill files into.',
                $clientId,
            ),
            $clientId,
        );
    }

    /**
     * A {@see SkillDeliveryMode::SingleConsolidatedFile} client carried a
     * field that only a per-skill client can act on. Nothing downstream
     * would ever read it, so it can only be a mistake.
     */
    public static function consolidatedCarriesPerSkillField(string $clientId, string $field): self
    {
        return new self(
            sprintf(
                'ClientCapabilities for client "%s" declares SingleConsolidatedFile delivery but declares a %s; '
                . 'a consolidated client writes exactly one guidance file and never a per-skill file.',
                $clientId,
                $field,
            ),
            $clientId,
        );
    }

    /**
     * A {@see SkillDeliveryMode::SingleConsolidatedFile} client required
     * frontmatter at byte 0. It emits no per-skill file, so the flag has no
     * output to govern — the contradiction the repair round closed.
     */
    public static function consolidatedRequiresFrontmatter(string $clientId): self
    {
        return new self(
            sprintf(
                'ClientCapabilities for client "%s" declares SingleConsolidatedFile delivery but requires frontmatter '
                . 'at byte 0; only a per-skill file carries frontmatter, so the flag would govern no output.',
                $clientId,
            ),
            $clientId,
        );
    }
}
