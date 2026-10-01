<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Site\Management;

use Waaseyaa\AI\Tools\ToolRegistryInterface;
use Waaseyaa\SiteContract\Management\ManagementInventoryInterface;
use Waaseyaa\SiteContract\Management\ManagementManifestParser;
use Waaseyaa\SiteContract\Management\ManagementVerificationResult;

/**
 * Read-only projection of an actual effective MCP tool registry. Optional like
 * mcp:serve: waaseyaa/ai-tools must be installed by the consumer. Does not resolve
 * a principal, widen a registry, dispatch tools, or infer domain policy.
 * Product bindings supply their actual capability ownership and semantics;
 * schemas, token capability, visibility and dry-run support come from the registry.
 * @api
 */
final readonly class ToolRegistryManagementInventory implements ManagementInventoryInterface
{
    /**
     * @param array<string, array<string, mixed>> $policies Actual product binding policies keyed by tool name;
     *   entries contain id, capability, tenant_scope, effects, idempotency,
     *   concurrency, verification. Not copied from the authored companion file.
     * @param list<ManagementVerificationResult> $results Executed check results.
     */
    public function __construct(
        private ToolRegistryInterface $registry,
        private string $source,
        private string $siteDigest,
        private array $policies,
        private bool $durableAudit,
        private bool $approvalGate,
        private array $results = [],
    ) {}

    public function sourceDigest(): string
    {
        return $this->source;
    }

    public function siteManifestDigest(): string
    {
        return $this->siteDigest;
    }

    public function operations(): iterable
    {
        $parser = new ManagementManifestParser();
        foreach ($this->registry->all() as $tool) {
            $policy = $this->policies[$tool->name] ?? null;
            if (!is_array($policy)) {
                throw new \InvalidArgumentException('Every exposed management tool requires a product-owned policy.');
            }
            if (array_diff(array_keys($policy), ['id', 'capability', 'tenant_scope', 'effects',
                'idempotency', 'concurrency', 'verification']) !== []) {
                throw new \InvalidArgumentException('Product policy cannot override registry-derived metadata.');
            }
            if ($tool->outputSchema === null) {
                throw new \InvalidArgumentException('Management tools require a typed result schema.');
            }
            $idempotency = $policy['idempotency'] ?? null;
            if ($tool->idempotent !== ($idempotency !== 'none')) {
                throw new \InvalidArgumentException('Retry policy contradicts the registered tool.');
            }
            $required = $tool->inputSchema['required'] ?? [];
            if (($idempotency === 'key' && !in_array('idempotency_key', $required, true))
                || (($policy['concurrency'] ?? null) === 'revision' && !in_array('expected_revision_id', $required, true))) {
                throw new \InvalidArgumentException('Retry/concurrency precondition is missing from the actual input schema.');
            }
            $effects = $policy['effects'] ?? [];
            if ($tool->destructive === ($effects === ['read'])) {
                throw new \InvalidArgumentException('Effect policy contradicts tool mutability.');
            }
            yield $parser->operation($policy + [
                'binding' => ['transport' => 'mcp', 'name' => $tool->name],
                'input_schema' => $tool->inputSchema + ['$schema' => 'https://json-schema.org/draft/2020-12/schema'],
                'output_schema' => $tool->outputSchema + ['$schema' => 'https://json-schema.org/draft/2020-12/schema'],
                'required_scopes' => [$tool->capability],
                'dry_run' => $tool->dryRunSupported ? 'supported' : ($tool->destructive ? 'unsupported' : 'not_applicable'),
                'audit' => $this->durableAudit ? 'durable' : 'best_effort',
                'approval' => $this->approvalGate && $tool->destructive ? 'required' : 'not_required',
            ]);
        }
    }

    public function verificationResults(): iterable
    {
        yield from $this->results;
    }
}
