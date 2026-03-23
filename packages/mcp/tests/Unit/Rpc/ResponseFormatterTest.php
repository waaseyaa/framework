<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Unit\Rpc;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mcp\Rpc\ResponseFormatter;

#[CoversClass(ResponseFormatter::class)]
final class ResponseFormatterTest extends TestCase
{
    private ResponseFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new ResponseFormatter();
    }

    #[Test]
    public function resultWrapsInJsonRpcEnvelope(): void
    {
        $response = $this->formatter->result(1, ['data' => 'test']);

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame(1, $response['id']);
        self::assertSame(['data' => 'test'], $response['result']);
    }

    #[Test]
    public function resultAcceptsNullId(): void
    {
        $response = $this->formatter->result(null, 'ok');

        self::assertNull($response['id']);
        self::assertSame('ok', $response['result']);
    }

    #[Test]
    public function errorWrapsInJsonRpcEnvelope(): void
    {
        $response = $this->formatter->error(42, -32602, 'Invalid params');

        self::assertSame('2.0', $response['jsonrpc']);
        self::assertSame(42, $response['id']);
        self::assertSame(-32602, $response['error']['code']);
        self::assertSame('Invalid params', $response['error']['message']);
    }

    #[Test]
    public function withStableContractMetaAddsContractFields(): void
    {
        $result = ['data' => 'test'];
        $enriched = $this->formatter->withStableContractMeta($result, 'get_entity');

        self::assertSame('v1.0', $enriched['meta']['contract_version']);
        self::assertSame('stable', $enriched['meta']['contract_stability']);
        self::assertSame('get_entity', $enriched['meta']['tool_invoked']);
        self::assertSame('get_entity', $enriched['meta']['tool']);
    }

    #[Test]
    public function withStableContractMetaPreservesExistingToolMeta(): void
    {
        $result = ['meta' => ['tool' => 'custom_tool']];
        $enriched = $this->formatter->withStableContractMeta($result, 'get_entity');

        self::assertSame('custom_tool', $enriched['meta']['tool']);
    }

    #[Test]
    public function canonicalToolNamePassesThroughNonAliased(): void
    {
        self::assertSame('get_entity', $this->formatter->canonicalToolName('get_entity'));
    }

    #[Test]
    public function formatToolContentWrapsAsTextContent(): void
    {
        $result = ['key' => 'value'];
        $formatted = $this->formatter->formatToolContent($result);

        self::assertArrayHasKey('content', $formatted);
        self::assertCount(1, $formatted['content']);
        self::assertSame('text', $formatted['content'][0]['type']);

        $decoded = json_decode($formatted['content'][0]['text'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('value', $decoded['key']);
    }

    #[Test]
    public function contractVersionReturnsCurrentVersion(): void
    {
        self::assertSame('v1.0', $this->formatter->contractVersion());
    }
}
