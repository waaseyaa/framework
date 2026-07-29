<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Content;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\AgentToolResult;
use Waaseyaa\EntityStorage\Exception\RevisionConflictException;
use Waaseyaa\Publishing\Exception\ContentPublishingException;

/**
 * One operation of a bundle-scoped content tool set.
 *
 * A thin transport adapter: the handler closure calls the publishing service
 * (or asset store) and returns a JSON-serializable payload; every structured
 * publishing failure maps to a machine-readable error envelope
 * `{code, message, errors?, meta?}` inside the MCP `isError` result — never a
 * string an agent has to parse heuristically. Tool inputs are pure data
 * (validated upstream by the publishing schema); no filesystem paths, SQL,
 * or executable content are ever accepted or echoed.
 *
 * @api
 */
final readonly class ContentOperationTool implements AgentToolInterface
{
    /**
     * @param array<string, mixed> $schema JSON Schema (draft 2020-12) for the tool input.
     * @param \Closure(array<string, mixed>, \Waaseyaa\Access\AuthorizationPrincipalInterface): array<string, mixed> $handler
     * @param list<string> $redactedArgumentKeys Argument keys replaced in audit trails (large/binary payloads).
     */
    public function __construct(
        private string $descriptionText,
        private array $schema,
        private \Closure $handler,
        private array $redactedArgumentKeys = ['content_base64'],
    ) {}

    public function execute(array $arguments, AccountInterface $account): AgentToolResult
    {
        // The MCP bridge resolves every caller to an immutable principal
        // (DecisionAccountResolver); the handler's typed parameter fails loud
        // on any out-of-contract caller.
        try {
            $payload = ($this->handler)($arguments, $account);

            return AgentToolResult::success(
                [['type' => 'text', 'text' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]],
            );
        } catch (ContentPublishingException $e) {
            return $this->errorEnvelope($e->errorCode, $e->getMessage(), $e->fieldErrors, $e->meta);
        } catch (RevisionConflictException $e) {
            return $this->errorEnvelope('REVISION_CONFLICT', $e->getMessage(), [], [
                'expected_revision_id' => $e->expectedRevisionId,
                'current_revision_id' => $e->currentRevisionId,
            ]);
        } catch (AssetRejectedException $e) {
            return $this->errorEnvelope('ASSET_REJECTED', $e->getMessage(), array_map(
                static fn(string $reason): array => ['field' => 'content_base64', 'message' => $reason],
                $e->reasons,
            ));
        }
        // Infrastructure failures deliberately propagate: the MCP bridge turns
        // them into a generic tool error without leaking internals, and they
        // must not masquerade as editorial validation problems.
    }

    public function dryRun(array $arguments, AccountInterface $account): AgentToolResult
    {
        return AgentToolResult::error('dry_run_not_supported');
    }

    public function argumentsForAudit(array $arguments): array
    {
        foreach ($this->redactedArgumentKeys as $key) {
            if (\array_key_exists($key, $arguments)) {
                $arguments[$key] = '[redacted:' . \strlen((string) $arguments[$key]) . ' bytes]';
            }
        }

        return $arguments;
    }

    public function inputSchema(): array
    {
        return $this->schema;
    }

    public function description(): string
    {
        return $this->descriptionText;
    }

    /**
     * @param list<array{field: string, message: string}> $fieldErrors
     * @param array<string, mixed> $meta
     */
    private function errorEnvelope(string $code, string $message, array $fieldErrors = [], array $meta = []): AgentToolResult
    {
        $body = ['code' => $code, 'message' => $message];
        if ($fieldErrors !== []) {
            $body['errors'] = $fieldErrors;
        }
        if ($meta !== []) {
            $body['meta'] = $meta;
        }

        return AgentToolResult::error(json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
