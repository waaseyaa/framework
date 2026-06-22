<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\Http\Router\SessionChannel;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;
use Waaseyaa\Wayfinding\Tests\Support\InMemoryEntityTypeManager;
use Waaseyaa\Wayfinding\Tests\Support\WidgetEntity;

#[CoversClass(EmitBeaconController::class)]
final class EmitBeaconControllerTest extends TestCase
{
    private BroadcastStorage $storage;

    protected function setUp(): void
    {
        $this->storage = new BroadcastStorage(DBALDatabase::createSqlite());
    }

    #[Test]
    public function emit_publishes_beacon_to_the_target_session_channel(): void
    {
        $response = $this->emit(
            account: $this->account(hasCapability: true),
            body: ['session' => 'tokenA', 'anchor_id' => 'field:widget:title', 'content' => 'Edit the title here', 'order' => 1],
        );

        self::assertSame(202, $response->getStatusCode());

        // Lands on the target session's private channel...
        $messages = $this->storage->poll(0, [SessionChannel::forToken('tokenA')]);
        self::assertCount(1, $messages);
        self::assertSame('wayfinding.beacon', $messages[0]['event']);
        self::assertSame('field:widget:title', $messages[0]['data']['anchor_id']);
        self::assertSame('Edit the title here', $messages[0]['data']['content']);
        self::assertSame(1, $messages[0]['data']['order']);
    }

    #[Test]
    public function a_second_session_receives_nothing(): void
    {
        $this->emit(
            account: $this->account(hasCapability: true),
            body: ['session' => 'tokenA', 'anchor_id' => 'field:widget:title', 'content' => 'For session A', 'order' => 1],
        );

        // NFR-001: another session's channel must not receive the beacon.
        self::assertCount(0, $this->storage->poll(0, [SessionChannel::forToken('tokenB')]));
    }

    #[Test]
    public function emit_without_capability_is_forbidden_and_publishes_nothing(): void
    {
        $response = $this->emit(
            account: $this->account(hasCapability: false),
            body: ['session' => 'tokenA', 'anchor_id' => 'field:widget:title', 'content' => 'denied', 'order' => 1],
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertCount(0, $this->storage->poll(0, [SessionChannel::forToken('tokenA')]));
    }

    #[Test]
    public function emit_with_unknown_anchor_is_rejected_and_publishes_nothing(): void
    {
        $response = $this->emit(
            account: $this->account(hasCapability: true),
            body: ['session' => 'tokenA', 'anchor_id' => 'field:widget:does-not-exist', 'content' => 'x', 'order' => 1],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->storage->poll(0, [SessionChannel::forToken('tokenA')]));
    }

    #[Test]
    public function emit_with_missing_content_is_rejected(): void
    {
        $response = $this->emit(
            account: $this->account(hasCapability: true),
            body: ['session' => 'tokenA', 'anchor_id' => 'field:widget:title'],
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertCount(0, $this->storage->poll(0, [SessionChannel::forToken('tokenA')]));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function emit(AccountInterface $account, array $body): \Symfony\Component\HttpFoundation\Response
    {
        $widget = new EntityType(
            id: 'widget',
            label: 'Widget',
            class: WidgetEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            translatable: false,
            revisionable: false,
        );
        $etm = new InMemoryEntityTypeManager(
            ['widget' => $widget],
            ['widget' => ['body' => ['type' => 'text_long', 'label' => 'Body']]],
        );

        $request = Request::create('/api/wayfinding/beacons', 'POST');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_parsed_body', $body);
        $request->attributes->set('_broadcast_storage', $this->storage);

        return new EmitBeaconController($etm)->emit($request);
    }

    private function account(bool $hasCapability): AccountInterface
    {
        $account = $this->createMock(AccountInterface::class);
        $account->method('id')->willReturn(42);
        $account->method('hasPermission')->willReturnCallback(
            static fn(string $permission): bool => $hasCapability && $permission === EmitBeaconController::CAPABILITY,
        );

        return $account;
    }
}
