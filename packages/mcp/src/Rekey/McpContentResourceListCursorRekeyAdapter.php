<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Rekey;

use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterPurposeVerification;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyConflictException;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;

/** Zero-row owner for short-lived sealed MCP content-resource list cursors. @api */
final readonly class McpContentResourceListCursorRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    public const string ID = 'mcp-content-resource-list-cursor-v1';

    public function __construct(private DatabaseInterface $database) {}

    public function databaseAuthority(): DatabaseInterface
    {
        return $this->database;
    }

    public function id(): string
    {
        return self::ID;
    }

    public function purposeIds(): array
    {
        return [ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR];
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return $this->zeroSnapshot($context, 'forward');
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new ApplicationMasterRekeyConflictException(
            'MCP content-resource list cursors are ephemeral and have no transition batches.',
        );
    }

    public function verify(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot): array
    {
        return $this->verifyZero($context, $snapshot, 'forward');
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return $this->zeroSnapshot($context, 'rollback');
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new ApplicationMasterRekeyConflictException(
            'MCP content-resource list cursor rollback is ephemeral and has no transition batches.',
        );
    }

    public function verifyRollback(ApplicationMasterRekeyContext $context, ApplicationMasterInventorySnapshot $snapshot): array
    {
        return $this->verifyZero($context, $snapshot, 'rollback');
    }

    private function zeroSnapshot(ApplicationMasterRekeyContext $context, string $direction): ApplicationMasterInventorySnapshot
    {
        $this->assertBoundary($context, $direction);

        return new ApplicationMasterInventorySnapshot($this->token($context, $direction), 0);
    }

    /** @return array<string, ApplicationMasterPurposeVerification> */
    private function verifyZero(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        string $direction,
    ): array {
        $this->assertBoundary($context, $direction);
        if ($snapshot->totalRecords !== 0 || !hash_equals($snapshot->token, $this->token($context, $direction))) {
            throw new ApplicationMasterRekeyConflictException('MCP content-resource list cursor snapshot is not exact.');
        }

        return [ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR => new ApplicationMasterPurposeVerification(
            0,
            hash('sha256', implode("\0", [
                'waaseyaa.mcp.content-resource-list-cursor-verification.v1',
                $context->request->requestDigest,
                $direction,
                $snapshot->token,
            ])),
        )];
    }

    private function assertBoundary(ApplicationMasterRekeyContext $context, string $direction): void
    {
        if ($context->database !== $this->database) {
            throw new ApplicationMasterRekeyConflictException(
                'MCP content-resource list cursor rekey requires the exact coordinator database authority.',
            );
        }
        $expected = $direction === 'forward' ? $context->request->toVersion : $context->request->fromVersion;
        if ($context->keyring->activeVersion() !== $expected) {
            throw new ApplicationMasterRekeyConflictException(
                'MCP content-resource list cursor active writer version is not exact.',
            );
        }
    }

    private function token(ApplicationMasterRekeyContext $context, string $direction): string
    {
        return hash('sha256', implode("\0", [
            'waaseyaa.mcp.content-resource-list-cursor-snapshot.v1',
            $context->request->requestDigest,
            $direction,
            'records:0',
        ]));
    }
}
