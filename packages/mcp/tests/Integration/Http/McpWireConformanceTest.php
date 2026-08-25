<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Integration\Http;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mcp\Tests\Support\Http\McpConformanceServer;
use Waaseyaa\Mcp\Tests\Support\Http\McpServerUnavailable;
use Waaseyaa\Mcp\Tests\Support\Http\RawHttpResponse;
use Waaseyaa\Mcp\Tests\Support\Http\RawJsonRpcClient;

/**
 * #2520 acceptance: the anonymous MCP tier driven over REAL HTTP by a generic
 * client, through the handshake a conforming client actually performs —
 * `initialize` → `notifications/initialized` → `tools/list` → `tools/call`.
 *
 * Every other MCP test in this package is in-process: a Symfony `Request` built
 * by `Request::create()`, an `McpEndpoint` resolved in the same PHP process, a
 * `Response` inspected as a PHP object. That is real wiring, but nothing is ever
 * serialised — and all three defects in #2520 live in JSON encoding, where a
 * PHP-structure assertion is blind. So the assertions here are made on the RAW
 * BYTES the socket delivered, and on a re-decode of those bytes that preserves
 * object identity, so `{}` is distinguishable from `[]`.
 *
 * The tool behind `tools/call` is a fixture (see the router script): this suite
 * asserts the endpoint's envelope, not any shipped tool's payload.
 *
 * Skips, never fails, when the environment cannot host a `php -S` server — no
 * resolvable PHP binary, no free port, or the server not ready inside its
 * bounded wait. Localhost only; nothing here needs a network.
 */
#[CoversNothing]
final class McpWireConformanceTest extends TestCase
{
    private ?McpConformanceServer $server = null;

    protected function setUp(): void
    {
        try {
            $this->server = McpConformanceServer::start();
        } catch (McpServerUnavailable $e) {
            self::markTestSkipped('MCP conformance server unavailable: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        // Unconditional, and safe when startup never produced a process — a
        // mid-test failure must not leave an orphan `php -S` behind.
        $this->server?->stop();
        $this->server = null;
    }

    // ------------------------------------------------------------- handshake

    #[Test]
    public function initialize_answers_a_json_object_result_over_the_wire(): void
    {
        $response = $this->client()->initialize(id: 1);

        self::assertSame(200, $response->statusCode, $this->diagnostics($response));
        self::assertSame('application/json', $response->header('Content-Type'));

        $typed = $response->decodeTyped();
        self::assertInstanceOf(\stdClass::class, $typed);
        self::assertSame('2.0', $typed->jsonrpc);
        self::assertSame(1, $typed->id);
        self::assertInstanceOf(\stdClass::class, $typed->result, 'A JSON-RPC Result must encode as an object.');
        self::assertIsString($typed->result->protocolVersion);
        self::assertInstanceOf(\stdClass::class, $typed->result->capabilities);
        self::assertInstanceOf(\stdClass::class, $typed->result->serverInfo);
    }

    #[Test]
    public function the_initialized_notification_is_accepted_with_an_empty_body(): void
    {
        $client = $this->client();
        $client->initialize(id: 1);

        $response = $client->notifyInitialized();

        // A notification has no id, so there is nothing to answer: 202 with a
        // zero-byte body is what a conforming client expects to read.
        self::assertSame(202, $response->statusCode, $this->diagnostics($response));
        self::assertSame('', $response->rawBody);
    }

    #[Test]
    public function tools_list_answers_a_json_object_result_carrying_the_tool_array(): void
    {
        $client = $this->handshaken();

        $response = $client->call('tools/list', id: 2);

        self::assertSame(200, $response->statusCode, $this->diagnostics($response));

        $typed = $response->decodeTyped();
        self::assertInstanceOf(\stdClass::class, $typed);
        self::assertInstanceOf(\stdClass::class, $typed->result);
        self::assertIsArray($typed->result->tools);
        self::assertSame(
            ['entity.read'],
            array_map(static fn(\stdClass $tool): string => $tool->name, $typed->result->tools),
        );
    }

    #[Test]
    public function tools_call_answers_a_json_object_result_with_a_content_array(): void
    {
        $client = $this->handshaken();

        $response = $client->call(
            'tools/call',
            ['name' => 'entity.read', 'arguments' => new \stdClass()],
            id: 3,
        );

        self::assertSame(200, $response->statusCode, $this->diagnostics($response));

        $typed = $response->decodeTyped();
        self::assertInstanceOf(\stdClass::class, $typed);
        self::assertInstanceOf(\stdClass::class, $typed->result);
        self::assertIsArray($typed->result->content);
        self::assertNotSame([], $typed->result->content);
        self::assertObjectNotHasProperty('isError', $typed->result);
    }

    // ------------------------------------------------------------------ ping

    /**
     * The load-bearing assertion.
     *
     * `handlePing()` returned `jsonRpcResult($id, [])`, and PHP encodes the
     * empty array as `[]`. The MCP `Result` type is an object, so the official
     * TypeScript SDK rejects the response with a ZodError — a protocol defect
     * that only exists on the wire and that no in-process assertion can see,
     * because in PHP the fixed and unfixed values are both `[]`.
     *
     * Asserted twice on purpose: once on the literal bytes, and once on a
     * re-decode that keeps `{}` and `[]` apart.
     */
    #[Test]
    public function ping_answers_an_empty_json_object_and_never_an_empty_array(): void
    {
        $response = $this->handshaken()->ping(id: 7);

        self::assertSame(200, $response->statusCode, $this->diagnostics($response));

        self::assertStringContainsString(
            '"result":{}',
            $response->rawBody,
            'A JSON-RPC Result is an object; ping must put `{}` on the wire.',
        );
        self::assertStringNotContainsString(
            '"result":[]',
            $response->rawBody,
            'PHP encodes the empty array as `[]`, which a schema-validating MCP client rejects.',
        );

        $typed = $response->decodeTyped();
        self::assertInstanceOf(\stdClass::class, $typed);
        self::assertSame(7, $typed->id);
        self::assertInstanceOf(
            \stdClass::class,
            $typed->result,
            'Re-decoded with objects preserved, ping\'s result must be an object, not an array.',
        );
        self::assertSame([], (array) $typed->result, 'ping\'s result is the EMPTY object.');
    }

    // --------------------------------------------------------------- harness

    private function client(): RawJsonRpcClient
    {
        self::assertInstanceOf(McpConformanceServer::class, $this->server);

        return $this->server->client();
    }

    /** A client that has completed the handshake, as a real one has before it calls anything. */
    private function handshaken(): RawJsonRpcClient
    {
        $client = $this->client();
        $initialize = $client->initialize(id: 1);
        self::assertSame(200, $initialize->statusCode, $this->diagnostics($initialize));
        $client->notifyInitialized();

        return $client;
    }

    private function diagnostics(RawHttpResponse $response): string
    {
        return sprintf(
            "wire body: %s\nserver log: %s",
            $response->rawBody === '' ? '(empty)' : $response->rawBody,
            $this->server?->diagnostics() ?? '(no server)',
        );
    }
}
