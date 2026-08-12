<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Tool\Wayfinding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Agent\Tests\Support\FakeEntityTypeManager;
use Waaseyaa\AI\Agent\Tool\Wayfinding\EmitBeaconTool;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Http\Router\SessionChannel;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;

/**
 * Acceptance for the Wayfinding emit MCP write tool (Phase 5): capability-gated
 * (`present guided content`, fail-closed — FR-003/NFR-002/SC-002), anchor-validated
 * against the published catalog (FR-005), and session-scoped delivery (LD-1).
 */
#[CoversClass(EmitBeaconTool::class)]
final class EmitBeaconToolTest extends TestCase
{
    private DBALDatabase $database;
    private BroadcastStorage $storage;
    private EmitBeaconTool $tool;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::broadcast($this->database);
        $this->storage = new BroadcastStorage($this->database);

        // A single registered type yields structural anchors like `view:widget`
        // (no schema fields required), so AnchorRegistry::isValid('view:widget') is true.
        $widget = new EntityType(id: 'widget', label: 'Widget', class: \stdClass::class, keys: ['id' => 'id']);
        $registry = new AnchorRegistry(new FakeEntityTypeManager([], ['widget' => $widget]));

        $this->tool = new EmitBeaconTool($registry, $this->database);
    }

    #[Test]
    public function emit_is_forbidden_without_the_capability(): void
    {
        $result = $this->tool->execute(
            ['session_token' => 'tokenA', 'anchor_id' => 'view:widget', 'content' => 'denied'],
            $this->account(hasCapability: false),
        );

        self::assertTrue($result->isError);
        self::assertSame('forbidden', $result->summary);
        self::assertCount(0, $this->storage->poll(0, [SessionChannel::forToken('tokenA')]));
    }

    #[Test]
    public function emit_with_unknown_anchor_is_rejected_and_publishes_nothing(): void
    {
        $result = $this->tool->execute(
            ['session_token' => 'tokenA', 'anchor_id' => 'field:widget:nope', 'content' => 'x'],
            $this->account(hasCapability: true),
        );

        self::assertTrue($result->isError);
        self::assertSame('unknown anchor', $result->summary);
        self::assertCount(0, $this->storage->poll(0, [SessionChannel::forToken('tokenA')]));
    }

    #[Test]
    public function emit_with_missing_session_token_is_rejected(): void
    {
        $result = $this->tool->execute(
            ['anchor_id' => 'view:widget', 'content' => 'x'],
            $this->account(hasCapability: true),
        );

        self::assertTrue($result->isError);
        self::assertSame('missing argument', $result->summary);
    }

    #[Test]
    public function emit_publishes_a_beacon_to_the_target_session_channel(): void
    {
        $result = $this->tool->execute(
            ['session_token' => 'tokenA', 'anchor_id' => 'view:widget', 'content' => 'Look here', 'order' => 2],
            $this->account(hasCapability: true),
        );

        self::assertFalse($result->isError);

        // Lands on the target session's private channel only (LD-1 / NFR-001).
        $messages = $this->storage->poll(0, [SessionChannel::forToken('tokenA')]);
        self::assertCount(1, $messages);
        self::assertSame('wayfinding.beacon', $messages[0]['event']);
        self::assertSame('view:widget', $messages[0]['data']['anchor_id']);
        self::assertSame('Look here', $messages[0]['data']['content']);
        self::assertSame(2, $messages[0]['data']['order']);
        self::assertCount(0, $this->storage->poll(0, [SessionChannel::forToken('tokenB')]));
    }

    private function account(bool $hasCapability): AccountInterface
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(42);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => $hasCapability && $permission === EmitBeaconController::CAPABILITY,
        );

        return $account;
    }
}
