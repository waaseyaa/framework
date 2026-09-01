<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Tool\Wayfinding;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\AI\Agent\Tests\Support\FakeEntityTypeManager;
use Waaseyaa\AI\Agent\Tool\Wayfinding\EmitBeaconTool;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Http\Router\SessionChannel;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;

/**
 * Cross-adapter matrix (#2746 acceptance): the HTTP {@see EmitBeaconController}
 * and MCP {@see EmitBeaconTool} independently implement anchor/content/order
 * validation and beacon construction, and have already drifted once (silent
 * HTTP self-target on a malformed "session" — regression-covered separately in
 * {@see \Waaseyaa\Wayfinding\Tests\Integration\EmitBeaconControllerTest}).
 * This asserts both adapters agree on outcome for every shared validation
 * dimension — capability, anchor, content, and order — so a future drift on
 * either side fails a shared test instead of going unnoticed.
 *
 * Target-selection semantics are intentionally NOT unified here (by design,
 * per the issue): HTTP self-targets on an omitted "session"; MCP always
 * requires an explicit "session_token". Both adapters are driven with an
 * explicit, valid target token in this matrix so the shared validation
 * dimensions are compared on equal footing.
 */
#[CoversNothing]
final class EmitBeaconAdapterParityTest extends TestCase
{
    private const string HTTP_TARGET_TOKEN = 'http-target-token';
    private const string MCP_TARGET_TOKEN = 'mcp-target-token';

    private DBALDatabase $database;
    private BroadcastStorage $storage;
    private EmitBeaconController $controller;
    private EmitBeaconTool $tool;

    protected function setUp(): void
    {
        $this->database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::broadcast($this->database);
        $this->storage = new BroadcastStorage($this->database);

        // A single registered type with no fields yields exactly one structural
        // anchor, `view:widget` (no schema needed), shared by both adapters.
        $widget = new EntityType(id: 'widget', label: 'Widget', class: \stdClass::class, keys: ['id' => 'id']);
        $entityTypeManager = new FakeEntityTypeManager([], ['widget' => $widget]);

        $this->controller = new EmitBeaconController(new AnchorRegistry($entityTypeManager));
        $this->tool = new EmitBeaconTool(new AnchorRegistry($entityTypeManager), $this->database);
    }

    /**
     * @return iterable<string, array{0: bool, 1: string, 2: string, 3: mixed, 4: bool}>
     */
    public static function sharedValidationMatrix(): iterable
    {
        yield 'valid emit' => [true, 'view:widget', 'Hello there', 1, true];
        yield 'capability denied' => [false, 'view:widget', 'Hello there', 1, false];
        yield 'unknown anchor' => [true, 'view:does-not-exist', 'Hello there', 1, false];
        yield 'empty content' => [true, 'view:widget', '', 1, false];
        yield 'oversize content' => [true, 'view:widget', str_repeat('a', 4001), 1, false];
        yield 'non-integer order' => [true, 'view:widget', 'Hello there', 'not-an-int', false];
    }

    #[Test]
    #[DataProvider('sharedValidationMatrix')]
    public function http_and_mcp_adapters_agree_on_shared_validation_outcomes(
        bool $hasCapability,
        string $anchorId,
        string $content,
        mixed $order,
        bool $expectSuccess,
    ): void {
        $httpResponse = $this->controller->emit($this->httpRequest($hasCapability, [
            'session' => self::HTTP_TARGET_TOKEN,
            'anchor_id' => $anchorId,
            'content' => $content,
            'order' => $order,
        ]));
        $mcpResult = $this->tool->execute(
            [
                'session_token' => self::MCP_TARGET_TOKEN,
                'anchor_id' => $anchorId,
                'content' => $content,
                'order' => $order,
            ],
            $this->account($hasCapability),
        );

        $httpMessages = $this->storage->poll(0, [SessionChannel::forToken(self::HTTP_TARGET_TOKEN)]);
        $mcpMessages = $this->storage->poll(0, [SessionChannel::forToken(self::MCP_TARGET_TOKEN)]);

        if ($expectSuccess) {
            self::assertSame(202, $httpResponse->getStatusCode(), 'HTTP adapter should have accepted this scenario.');
            self::assertFalse($mcpResult->isError, 'MCP adapter should have accepted this scenario.');

            self::assertCount(1, $httpMessages);
            self::assertCount(1, $mcpMessages);

            // Same anchor, content, and order land in the payload on both sides.
            self::assertSame($anchorId, $httpMessages[0]['data']['anchor_id']);
            self::assertSame($anchorId, $mcpMessages[0]['data']['anchor_id']);
            self::assertSame($content, $httpMessages[0]['data']['content']);
            self::assertSame($content, $mcpMessages[0]['data']['content']);
            self::assertSame($order, $httpMessages[0]['data']['order']);
            self::assertSame($order, $mcpMessages[0]['data']['order']);
            self::assertSame('wayfinding.beacon', $httpMessages[0]['event']);
            self::assertSame('wayfinding.beacon', $mcpMessages[0]['event']);
        } else {
            self::assertGreaterThanOrEqual(400, $httpResponse->getStatusCode(), 'HTTP adapter should have rejected this scenario.');
            self::assertTrue($mcpResult->isError, 'MCP adapter should have rejected this scenario.');

            // Rejected cases persist no retained/live state on either side.
            self::assertCount(0, $httpMessages);
            self::assertCount(0, $mcpMessages);
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function httpRequest(bool $hasCapability, array $body): Request
    {
        $request = Request::create('/api/wayfinding/beacons', 'POST');
        $request->attributes->set('_account', $this->account($hasCapability));
        $request->attributes->set('_parsed_body', $body);
        $request->attributes->set('_broadcast_storage', $this->storage);

        return $request;
    }

    private function account(bool $hasCapability): AccountInterface
    {
        $account = $this->createStub(AuthorizationPrincipalInterface::class);
        $account->method('id')->willReturn(42);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => $hasCapability && $permission === EmitBeaconController::CAPABILITY,
        );

        return $account;
    }
}
