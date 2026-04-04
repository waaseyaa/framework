<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Http\Router;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Api\Controller\BroadcastStorage;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Foundation\Http\Router\WaaseyaaContext;

#[CoversClass(WaaseyaaContext::class)]
final class WaaseyaaContextTest extends TestCase
{
    #[Test]
    public function from_request_extracts_all_attributes(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $broadcastStorage = new BroadcastStorage(DBALDatabase::createSqlite());

        $request = Request::create('/test', 'POST');
        $request->query->set('page', '2');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_parsed_body', ['title' => 'Hello']);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);

        $ctx = WaaseyaaContext::fromRequest($request);

        self::assertSame($account, $ctx->account);
        self::assertSame(['title' => 'Hello'], $ctx->parsedBody);
        self::assertSame('POST', $ctx->method);
        self::assertSame('2', $ctx->query['page']);
        self::assertSame($broadcastStorage, $ctx->broadcastStorage);
    }

    #[Test]
    public function from_request_handles_null_parsed_body(): void
    {
        $account = $this->createStub(AccountInterface::class);
        $broadcastStorage = new BroadcastStorage(DBALDatabase::createSqlite());

        $request = Request::create('/test', 'GET');
        $request->attributes->set('_account', $account);
        $request->attributes->set('_parsed_body', null);
        $request->attributes->set('_broadcast_storage', $broadcastStorage);

        $ctx = WaaseyaaContext::fromRequest($request);

        self::assertNull($ctx->parsedBody);
        self::assertSame('GET', $ctx->method);
    }
}
