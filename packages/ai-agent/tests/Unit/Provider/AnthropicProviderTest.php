<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Provider;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Agent\Provider\AnthropicProvider;
use Waaseyaa\AI\Agent\Provider\MessageRequest;
use Waaseyaa\AI\Agent\Provider\MessageResponse;
use Waaseyaa\AI\Agent\Provider\StreamChunk;
use Waaseyaa\AI\Agent\Provider\TransportException;

#[CoversClass(AnthropicProvider::class)]
final class AnthropicProviderTest extends TestCase
{
    public function testBuildRequestBodyAppliesModel(): void
    {
        $provider = new AnthropicProvider(
            apiKey: 'test-key',
            model: 'claude-sonnet-4-6',
        );

        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hello']],
            maxTokens: 1024,
        );

        $body = $provider->buildRequestBody($request);

        $this->assertSame('claude-sonnet-4-6', $body['model']);
        $this->assertSame(1024, $body['max_tokens']);
        $this->assertCount(1, $body['messages']);
    }

    public function testBuildRequestBodyAppliesCacheControlToSystem(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            system: 'You are a helpful assistant.',
        );

        $body = $provider->buildRequestBody($request);

        // System prompt should have cache_control
        $this->assertIsArray($body['system']);
        $this->assertSame('ephemeral', $body['system'][0]['cache_control']['type']);
    }

    public function testBuildRequestBodyAppliesCacheControlToLastTool(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $request = new MessageRequest(
            messages: [['role' => 'user', 'content' => 'Hi']],
            tools: [
                ['name' => 'tool_a', 'description' => 'A', 'input_schema' => []],
                ['name' => 'tool_b', 'description' => 'B', 'input_schema' => []],
            ],
        );

        $body = $provider->buildRequestBody($request);

        // Only last tool gets cache_control
        $this->assertArrayNotHasKey('cache_control', $body['tools'][0]);
        $this->assertSame('ephemeral', $body['tools'][1]['cache_control']['type']);
    }

    public function testParseResponseCreatesMessageResponse(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $apiResponse = [
            'content' => [
                ['type' => 'text', 'text' => 'Hello!'],
            ],
            'stop_reason' => 'end_turn',
            'usage' => [
                'input_tokens' => 10,
                'output_tokens' => 5,
            ],
        ];

        $response = $provider->parseResponse($apiResponse);

        $this->assertInstanceOf(MessageResponse::class, $response);
        $this->assertSame('Hello!', $response->getText());
        $this->assertSame('end_turn', $response->stopReason);
        $this->assertSame(10, $response->usage['input_tokens']);
    }

    public function testParseResponseExtractsToolUseBlocks(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $apiResponse = [
            'content' => [
                ['type' => 'text', 'text' => 'Let me check.'],
                ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'read_node', 'input' => ['id' => '5']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 20, 'output_tokens' => 15],
        ];

        $response = $provider->parseResponse($apiResponse);

        $this->assertSame('tool_use', $response->stopReason);
        $toolBlocks = $response->getToolUseBlocks();
        $this->assertCount(1, $toolBlocks);
        $this->assertSame('read_node', $toolBlocks[0]->name);
        $this->assertSame(['id' => '5'], $toolBlocks[0]->input);
    }

    // --- Streaming tests (Task 12) ---

    public function testParseSseLineExtractsTextDelta(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $chunks = [];
        $onChunk = function (StreamChunk $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        };

        $events = [
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" world"}}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
        ];

        $responseData = $provider->parseSseEvents($events, $onChunk);

        $this->assertCount(3, $chunks); // 2 text_delta + 1 message_stop
        $this->assertSame('text_delta', $chunks[0]->type);
        $this->assertSame('Hello', $chunks[0]->text);
        $this->assertSame(' world', $chunks[1]->text);
    }

    public function testParseSseLineExtractsToolUseBlocks(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $chunks = [];
        $onChunk = function (StreamChunk $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        };

        $events = [
            'event: content_block_start',
            'data: {"type":"content_block_start","index":0,"content_block":{"type":"tool_use","id":"tu_1","name":"read_node","input":{}}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"{\"id\":"}}',
            '',
            'event: content_block_delta',
            'data: {"type":"content_block_delta","index":0,"delta":{"type":"input_json_delta","partial_json":"\"5\"}"}}',
            '',
            'event: content_block_stop',
            'data: {"type":"content_block_stop","index":0}',
            '',
            'event: message_stop',
            'data: {"type":"message_stop"}',
        ];

        $responseData = $provider->parseSseEvents($events, $onChunk);

        // Should have tool_use_start, 2 tool_use_delta, tool_use_end, message_stop
        $toolStartChunks = array_filter($chunks, fn ($c) => $c->type === 'tool_use_start');
        $this->assertCount(1, $toolStartChunks);
    }

    public function testAssertStreamTransferSucceededAcceptsAGoodTransfer(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        // execResult true (RETURNTRANSFER is off for streaming), no errno, real status.
        $provider->assertStreamTransferSucceeded(true, 0, '', 200);

        $this->expectNotToPerformAssertions();
    }

    public function testAssertStreamTransferSucceededThrowsWhenCurlExecReturnsFalse(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('SSL certificate problem');

        // A transport failure: curl_exec() returned false, errno set, no HTTP status.
        $provider->assertStreamTransferSucceeded(false, 60, 'SSL certificate problem', 0);
    }

    public function testAssertStreamTransferSucceededThrowsOnCurlErrnoEvenWithoutMessage(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $this->expectException(TransportException::class);
        // Falls back to a synthetic detail when curl_error() is empty.
        $this->expectExceptionMessage('no HTTP status received');

        $provider->assertStreamTransferSucceeded(false, 28, '', 0);
    }

    public function testAssertStreamTransferSucceededThrowsWhenNoHttpStatusReceived(): void
    {
        $provider = new AnthropicProvider(apiKey: 'test-key');

        $this->expectException(TransportException::class);

        // curl_exec() can return true while still never receiving a status line;
        // httpCode === 0 must still be treated as a failed transfer.
        $provider->assertStreamTransferSucceeded(true, 0, '', 0);
    }
}
