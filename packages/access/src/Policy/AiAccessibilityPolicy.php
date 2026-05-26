<?php

declare(strict_types=1);

namespace Waaseyaa\Access\Policy;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\FieldAccessPolicyInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Per-file AI-access policy for media and attachment entities.
 *
 * Enforces the tri-state `ai_accessible` field (yes/no/inherit) for
 * agent-initiated requests. Non-agent requests are unaffected — this
 * policy returns Neutral for them, letting other policies govern access.
 *
 * Detection mechanism (D-D2, option b): the presence of the
 * `_agent_run_id` attribute on the Symfony Request signals an agent run.
 * Per C-002, this class has NO imports from `Waaseyaa\AI\*` — the
 * attribute key is read as a plain string from the request bag.
 *
 * Entity-level semantics (CLAUDE.md): entity-level uses isAllowed()
 * (deny unless granted). Returning Neutral on `'inherit'` or `'yes'`
 * lets other policies (e.g. MediaAccessPolicy) make the affirmative
 * decision. Forbidden on `'no'` + agent request is the hard gate.
 *
 * Field-level semantics (CLAUDE.md): open-by-default — Neutral = accessible,
 * only Forbidden restricts. fieldAccess() mirrors access() so the
 * ai_accessible field itself is not redacted from agent reads when the
 * value is 'yes' or 'inherit'.
 *
 * Until M-A4 (classification engine) ships, `'inherit'` resolves to
 * `'yes'` at the AccessChecker level (neutral → not forbidden → accessible).
 * This is the access-preserving default per C-004.
 *
 * @api
 */
#[PolicyAttribute(entityType: ['media', 'attachment'])]
final class AiAccessibilityPolicy implements AccessPolicyInterface, FieldAccessPolicyInterface
{
    /**
     * The Symfony Request attribute key set by the agent executor (D-D2 mechanism b).
     * Intentionally a string constant — no `use Waaseyaa\AI\*` imports per C-002.
     */
    private const AGENT_RUN_ID_ATTRIBUTE = '_agent_run_id';

    public function __construct(
        private readonly ?Request $request = null,
    ) {}

    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'media' || $entityTypeId === 'attachment';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        $aiAccessible = (string) ($entity->get('ai_accessible') ?? 'inherit');

        if ($aiAccessible === 'no' && $this->isAgentRequest()) {
            return AccessResult::forbidden(
                'AI tool access denied: ai_accessible is set to "no" for this file.',
            );
        }

        // 'yes' and 'inherit' both return Neutral — other policies determine
        // whether access is ultimately granted (e.g. MediaAccessPolicy).
        // 'inherit' defers to classification; until M-A4 ships, neutral
        // resolves to accessible (access-preserving default, C-004).
        return AccessResult::neutral(
            sprintf('ai_accessible is "%s"; no opinion from AiAccessibilityPolicy.', $aiAccessible),
        );
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        // Create access is not governed by the per-file AI toggle.
        return AccessResult::neutral('AiAccessibilityPolicy does not govern create access.');
    }

    /**
     * Field-level access mirrors entity-level access.
     *
     * Open-by-default: Neutral = accessible, only Forbidden restricts.
     * When ai_accessible = 'no' and this is an agent request, forbid access
     * to all fields on the entity (content redaction).
     */
    public function fieldAccess(
        EntityInterface $entity,
        string $fieldName,
        string $operation,
        AccountInterface $account,
    ): AccessResult {
        $aiAccessible = (string) ($entity->get('ai_accessible') ?? 'inherit');

        if ($aiAccessible === 'no' && $this->isAgentRequest()) {
            return AccessResult::forbidden(
                sprintf(
                    'AI tool field access denied: ai_accessible is "no" for this file (field: %s).',
                    $fieldName,
                ),
            );
        }

        return AccessResult::neutral(
            sprintf('ai_accessible is "%s"; field "%s" is accessible.', $aiAccessible, $fieldName),
        );
    }

    /**
     * Returns true when the current request carries an agent run ID attribute,
     * indicating this is an AI agent-initiated request (D-D2 mechanism b).
     */
    private function isAgentRequest(): bool
    {
        if ($this->request === null) {
            return false;
        }

        return $this->request->attributes->has(self::AGENT_RUN_ID_ATTRIBUTE);
    }
}
