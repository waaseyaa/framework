<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tool\Bimaaji;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AbstractAgentTool;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\AI\Tools\Attribute\AsAgentTool;
use Waaseyaa\Bimaaji\Mutation\MutationRequest;
use Waaseyaa\Bimaaji\Mutation\MutationValidator;

/**
 * Mutation-proposal tool.
 *
 * Wraps {@see MutationValidator::validate()}. The tool surfaces the full
 * {@see \Waaseyaa\Bimaaji\Mutation\MutationResult::toArray()} payload — both
 * accept and reject outcomes ride the same success envelope so an agent
 * can introspect `data.status` without parsing error semantics. Gated by
 * `bimaaji.mutate`; no filesystem side effects (validator is pure).
 *
 * Capability: `bimaaji.mutate`.
 *
 * @api
 */
#[AsAgentTool(
    name: 'bimaaji_propose_mutation',
    capability: 'bimaaji.mutate',
    destructive: false,
    dryRunSupported: true,
    category: 'bimaaji',
)]
final class ProposeMutationTool extends AbstractAgentTool
{
    public function __construct(
        private readonly MutationValidator $validator,
    ) {}

    public function description(): string
    {
        return 'Validate a proposed schema mutation against the current application graph. Returns the structured MutationResult payload (status, request, errors).';
    }

    public function inputSchema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'properties' => [
                'operation' => [
                    'type' => 'string',
                    'description' => 'Mutation operation key (e.g. add_field, add_entity_type).',
                ],
                'entity_type' => [
                    'type' => 'string',
                    'description' => 'Target entity-type id.',
                ],
                'field' => [
                    'type' => ['string', 'null'],
                    'description' => 'Target field machine name when the operation is field-scoped.',
                ],
                'parameters' => [
                    'type' => 'object',
                    'description' => 'Operation-specific parameters bag.',
                    'additionalProperties' => true,
                ],
            ],
            'required' => ['operation', 'entity_type'],
            'additionalProperties' => false,
        ];
    }

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        $denied = $this->requireCapability('bimaaji.mutate', $account);
        if ($denied !== null) {
            return $denied;
        }

        $request = $this->buildRequest($arguments);
        if ($request instanceof AgentToolResult) {
            return $request;
        }

        try {
            $result = $this->validator->validate($request);
        } catch (\Throwable $e) {
            return AgentToolResult::error(
                message: sprintf('bimaaji_propose_mutation: [%s] %s', $e::class, $e->getMessage()),
                summary: 'validation failed',
            );
        }

        $payload = $result->toArray();

        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            return $this->internalError('bimaaji_propose_mutation', $e);
        }

        return AgentToolResult::success(
            content: [['type' => 'text', 'text' => $json]],
            summary: sprintf(
                'Mutation %s on %s: %s',
                $request->operation,
                $request->entityType,
                $payload['status'],
            ),
            structuredContent: $payload,
        );
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return $this->execute($arguments, $account);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function buildRequest(array $arguments): MutationRequest|AgentToolResult
    {
        $operation = $arguments['operation'] ?? null;
        if (!is_string($operation) || $operation === '') {
            return AgentToolResult::error(
                message: 'bimaaji_propose_mutation: missing required argument "operation".',
                summary: 'missing argument',
            );
        }

        $entityType = $arguments['entity_type'] ?? null;
        if (!is_string($entityType) || $entityType === '') {
            return AgentToolResult::error(
                message: 'bimaaji_propose_mutation: missing required argument "entity_type".',
                summary: 'missing argument',
            );
        }

        $field = $arguments['field'] ?? null;
        if ($field !== null && !is_string($field)) {
            return AgentToolResult::error(
                message: 'bimaaji_propose_mutation: "field" must be a string or null.',
                summary: 'invalid argument',
            );
        }

        $parameters = $arguments['parameters'] ?? [];
        if (!is_array($parameters)) {
            return AgentToolResult::error(
                message: 'bimaaji_propose_mutation: "parameters" must be an object.',
                summary: 'invalid argument',
            );
        }

        /** @var array<string, mixed> $parameters */

        return new MutationRequest(
            operation: $operation,
            entityType: $entityType,
            field: $field,
            parameters: $parameters,
        );
    }
}
