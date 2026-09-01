<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Tests\Support\RuntimeSchemaMigrations;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Http\Router\SessionChannel;
use Waaseyaa\Wayfinding\Anchor\AnchorRegistry;
use Waaseyaa\Wayfinding\Http\EmitBeaconController;
use Waaseyaa\Wayfinding\Tests\Support\CountingEntityTypeManager;
use Waaseyaa\Wayfinding\Tests\Support\InMemoryEntityTypeManager;
use Waaseyaa\Wayfinding\Tests\Support\WidgetEntity;

#[CoversClass(EmitBeaconController::class)]
final class EmitBeaconControllerTest extends TestCase
{
    private BroadcastStorage $storage;

    protected function setUp(): void
    {
        $database = DBALDatabase::createSqlite();
        RuntimeSchemaMigrations::broadcast($database);
        $this->storage = new BroadcastStorage($database);
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
     * Regression for #2746: a *present but malformed* "session" member (null,
     * empty string, a non-string scalar, or an array) used to fall through the
     * `is_string($token) && $token !== ''` guard into the omitted-target
     * branch, which silently self-targets the caller's own session instead of
     * rejecting the request. Only an *absent* "session" key may self-target;
     * a present value must be a non-empty string or the request is rejected.
     *
     * Runs in a separate process because it pins the process-global
     * `session_id()` (see {@see SessionTokenControllerTest}) — without an
     * active session, a malformed token already 422s for the wrong reason
     * ("No target session"), masking the bug this test guards against.
     *
     * @param null|bool|int|float|string|list<mixed> $malformedSession
     */
    #[Test]
    #[DataProvider('malformedSessionValues')]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function malformed_session_values_are_rejected_instead_of_self_targeting(
        null|bool|int|float|string|array $malformedSession,
    ): void {
        session_id('caller-own-session-id');
        $selfTargetChannel = SessionChannel::forSessionId('caller-own-session-id');

        $response = $this->emit(
            account: $this->account(hasCapability: true),
            body: ['session' => $malformedSession, 'anchor_id' => 'field:widget:title', 'content' => 'x', 'order' => 1],
        );

        self::assertSame(422, $response->getStatusCode());
        // The defect: this used to succeed (202) and land on the caller's OWN
        // session channel — an intended remote-target emit silently self-targeted.
        self::assertCount(0, $this->storage->poll(0, [$selfTargetChannel]));
    }

    /**
     * @return iterable<string, array{0: null|bool|int|float|string|list<mixed>}>
     */
    public static function malformedSessionValues(): iterable
    {
        yield 'null' => [null];
        yield 'empty string' => [''];
        yield 'integer' => [123];
        yield 'boolean true' => [true];
        yield 'boolean false' => [false];
        yield 'float' => [1.5];
        yield 'array' => [['nested' => 'value']];
    }

    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function omitting_session_still_self_targets_the_callers_own_session(): void
    {
        session_id('caller-own-session-id');
        $selfTargetChannel = SessionChannel::forSessionId('caller-own-session-id');

        $response = $this->emit(
            account: $this->account(hasCapability: true),
            body: ['anchor_id' => 'field:widget:title', 'content' => 'self-guided tip', 'order' => 1],
        );

        self::assertSame(202, $response->getStatusCode());
        $messages = $this->storage->poll(0, [$selfTargetChannel]);
        self::assertCount(1, $messages);
        self::assertSame('field:widget:title', $messages[0]['data']['anchor_id']);
    }

    #[Test]
    public function emit_reuses_the_injected_registry_instead_of_rebuilding_per_request(): void
    {
        // Sibling of AnchorCatalogControllerTest's reuse test (audit
        // L4-wayfinding.md, MAJOR finding): emit-time anchor validation used
        // to `new AnchorRegistry(...)` per request; it must now reuse one
        // injected registry's memoized catalog across repeated emits.
        $etm = new CountingEntityTypeManager($this->entityTypeManager());
        $controller = new EmitBeaconController(new AnchorRegistry($etm));

        $body = ['session' => 'tokenA', 'anchor_id' => 'field:widget:title', 'content' => 'Hi', 'order' => 1];
        $first = $controller->emit($this->request($this->account(hasCapability: true), $body));
        $second = $controller->emit($this->request($this->account(hasCapability: true), $body));

        self::assertSame(202, $first->getStatusCode());
        self::assertSame(202, $second->getStatusCode());
        // A per-request catalog rebuild (the pre-fix behavior) would report 2.
        self::assertSame(1, $etm->getDefinitionsCallCount);
        self::assertSame(1, $etm->resolveFieldDefinitionsCallCount);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function emit(AccountInterface $account, array $body): \Symfony\Component\HttpFoundation\Response
    {
        $controller = new EmitBeaconController(new AnchorRegistry($this->entityTypeManager()));

        return $controller->emit($this->request($account, $body));
    }

    private function entityTypeManager(): EntityTypeManagerInterface
    {
        $widget = new EntityType(
            id: 'widget',
            label: 'Widget',
            class: WidgetEntity::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'title'],
            translatable: false,
            revisionable: false,
        );

        return new InMemoryEntityTypeManager(
            ['widget' => $widget],
            ['widget' => ['body' => ['type' => 'text_long', 'label' => 'Body']]],
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(AccountInterface $account, array $body): Request
    {
        $request = Request::create('/api/wayfinding/beacons', 'POST');
        $request->attributes->set('_account', $account);
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
