<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Site\Management;

use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\AgentToolInterface;
use Waaseyaa\AI\Tools\Catalogue\AttributeToolRegistry;
use Waaseyaa\AI\Tools\Registry\CapabilityScopedToolRegistry;
use Waaseyaa\CLI\Site\Management\ToolRegistryManagementInventory;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\SiteContract\Management\ManagementConformance;
use Waaseyaa\SiteContract\Management\ManagementManifestParser;
use Waaseyaa\SiteContract\Tests\Fixtures\Management\ManagementFixture as F;
use Psr\Container\ContainerInterface;

final class ToolRegistryManagementInventoryTest extends TestCase
{
    private function registry(): AttributeToolRegistry
    {
        return new AttributeToolRegistry(new PackageManifest(), $this->createStub(ContainerInterface::class));
    }

    private function tool(bool $dryRun = false): AgentTool
    {
        $schema = F::operation()['input_schema'];
        $schema['required'] = ['idempotency_key', 'expected_revision_id'];
        $schema['properties'] = ['idempotency_key' => ['type' => 'string'], 'expected_revision_id' => ['type' => 'integer']];
        return new AgentTool('article.updateDraft', 'edit articles', true, $dryRun, 'content',
            $schema, $this->createStub(AgentToolInterface::class), outputSchema: F::operation()['output_schema'], idempotent: true);
    }

    private function policy(): array
    {
        return ['article.updateDraft' => ['id' => 'content.updateDraft', 'capability' => 'forms', 'tenant_scope' => 'required',
            'effects' => ['update'], 'idempotency' => 'key', 'concurrency' => 'revision', 'verification' => ['two-tenant-update']]];
    }

    public function testActualRegistrationSuppliesSchemasCapabilityAndPreviewWithoutExecution(): void
    {
        $registry = $this->registry(); $registry->register($this->tool());
        $inventory = new ToolRegistryManagementInventory($registry, str_repeat('c', 64), str_repeat('b', 64), $this->policy(), true, true);
        $op = iterator_to_array($inventory->operations())[0];
        self::assertSame(['edit articles'], $op->metadata['required_scopes']);
        self::assertSame('unsupported', $op->metadata['dry_run']);
        self::assertSame('revision', $op->metadata['concurrency']);
        self::assertSame('required', $op->metadata['approval']);
        self::assertSame($registry->get('article.updateDraft')->inputSchema, $op->metadata['input_schema']);
        self::assertSame([], iterator_to_array($inventory->verificationResults()));
        $doc = F::document($op->toArray());
        self::assertSame(['SITE041_MANAGEMENT_VERIFICATION_UNPROVEN'], array_column(new ManagementConformance()->inspect(
            F::manifest($doc), F::site(), str_repeat('c', 64), $inventory), 'id'));
    }

    public function testEffectiveRegistryFilteringMakesHiddenOperationMissing(): void
    {
        $registry = $this->registry(); $registry->register($this->tool());
        $full = new ToolRegistryManagementInventory($registry, str_repeat('c', 64), str_repeat('b', 64), $this->policy(), true, true);
        $op = iterator_to_array($full->operations())[0];
        $filtered = new CapabilityScopedToolRegistry($registry, []);
        $inventory = new ToolRegistryManagementInventory($filtered, str_repeat('c', 64), str_repeat('b', 64), $this->policy(), true, true);
        self::assertSame(['SITE039_MANAGEMENT_OPERATION_MISSING'], array_column(new ManagementConformance()->inspect(
            F::manifest(F::document($op->toArray())), F::site(), str_repeat('c', 64), $inventory), 'id'));
    }

    public function testPolicyCannotOverrideActualScopesOrDryRun(): void
    {
        $registry = $this->registry(); $registry->register($this->tool());
        $policy = $this->policy(); $policy['article.updateDraft']['dry_run'] = 'supported';
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array(new ToolRegistryManagementInventory($registry, str_repeat('c', 64), str_repeat('b', 64), $policy, true, true)->operations());
    }

    public function testMissingPolicyAndFalseIdempotencyClaimFailClosed(): void
    {
        $registry = $this->registry(); $registry->register($this->tool());
        foreach ([[], ['article.updateDraft' => $this->policy()['article.updateDraft'] + ['input_schema' => []]]] as $policy) {
            try {
                iterator_to_array(new ToolRegistryManagementInventory($registry, str_repeat('c', 64), str_repeat('b', 64), $policy, true, true)->operations());
                self::fail('Misleading policy accepted.');
            } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
        $policy = $this->policy(); $policy['article.updateDraft']['idempotency'] = 'none';
        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array(new ToolRegistryManagementInventory($registry, str_repeat('c', 64), str_repeat('b', 64), $policy, true, true)->operations());
    }
}
