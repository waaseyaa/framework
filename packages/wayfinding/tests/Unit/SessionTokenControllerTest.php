<?php

declare(strict_types=1);

namespace Waaseyaa\Wayfinding\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Waaseyaa\Foundation\Http\Router\SessionChannel;
use Waaseyaa\Wayfinding\Http\SessionTokenController;

#[CoversClass(SessionTokenController::class)]
final class SessionTokenControllerTest extends TestCase
{
    #[Test]
    public function returns_the_callers_own_token_matching_the_connected_frame_derivation(): void
    {
        $session = new Session(new MockArraySessionStorage());
        $session->start();
        $request = Request::create('/api/wayfinding/session', 'GET');
        $request->setSession($session);

        $response = (new SessionTokenController())->show($request);

        self::assertSame(200, $response->getStatusCode());

        /** @var array{data: array{sessionToken: string, channel: string}} $body */
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // The endpoint returns exactly the value the SSE `connected` frame carries
        // — the supported, race-free read path (P0-2).
        $expected = SessionChannel::tokenForSessionId($session->getId());
        self::assertSame($expected, $body['data']['sessionToken']);
        self::assertSame('session:' . $expected, $body['data']['channel']);
    }

    // Isolated process: the controller falls back to the global PHP session_id()
    // when the request carries no Symfony session, and session_id() is
    // process-global — another test in the full suite can leave one set, which
    // would make this no-session path return a real token. A fresh process keeps
    // the assertion deterministic.
    #[Test]
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function returns_null_when_there_is_no_session(): void
    {
        $request = Request::create('/api/wayfinding/session', 'GET');

        $response = (new SessionTokenController())->show($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($body['data']['sessionToken']);
        self::assertNull($body['data']['channel']);
    }
}
