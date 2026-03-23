<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

/**
 * Anthropic Messages API provider using cURL.
 */
final class AnthropicProvider implements StreamingProviderInterface
{
    private const string API_URL = 'https://api.anthropic.com/v1/messages';
    private const string API_VERSION = '2023-06-01';
    private const string DEFAULT_MODEL = 'claude-sonnet-4-20250514';

    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = self::DEFAULT_MODEL,
    ) {}

    public function sendMessage(MessageRequest $request): MessageResponse
    {
        $body = $this->buildRequestBody($request);
        $responseData = $this->httpPost(self::API_URL, $body);

        return $this->parseResponse($responseData);
    }

    public function streamMessage(MessageRequest $request, callable $onChunk): MessageResponse
    {
        $body = $this->buildRequestBody($request);
        $body['stream'] = true;

        return $this->httpPostStreaming(self::API_URL, $body, $onChunk);
    }

    /**
     * Build the API request body with prompt caching applied.
     *
     * @return array<string, mixed>
     */
    public function buildRequestBody(MessageRequest $request): array
    {
        $body = $request->toArray();
        $body['model'] = $this->model;

        // Apply cache_control to system prompt
        if (isset($body['system']) && \is_string($body['system'])) {
            $body['system'] = [
                [
                    'type' => 'text',
                    'text' => $body['system'],
                    'cache_control' => ['type' => 'ephemeral'],
                ],
            ];
        }

        // Apply cache_control to last tool definition
        if (isset($body['tools']) && \is_array($body['tools']) && $body['tools'] !== []) {
            $lastIndex = \count($body['tools']) - 1;
            $body['tools'][$lastIndex]['cache_control'] = ['type' => 'ephemeral'];
        }

        return $body;
    }

    /**
     * Parse an API response array into a MessageResponse.
     *
     * @param array<string, mixed> $data
     */
    public function parseResponse(array $data): MessageResponse
    {
        return new MessageResponse(
            content: $data['content'] ?? [],
            stopReason: $data['stop_reason'] ?? 'end_turn',
            usage: $data['usage'] ?? [],
        );
    }

    /**
     * Parse SSE event lines into StreamChunks (public for testing).
     *
     * @param string[] $lines
     * @param callable(StreamChunk): void $onChunk
     * @return array{content: array<int, array<string, mixed>>, stop_reason: string}
     */
    public function parseSseEvents(array $lines, callable $onChunk): array
    {
        $contentBlocks = [];
        $currentToolUse = null;
        $currentToolJson = '';
        $stopReason = 'end_turn';

        $currentEvent = '';
        foreach ($lines as $line) {
            if (\str_starts_with($line, 'event: ')) {
                $currentEvent = \substr($line, 7);
                continue;
            }

            if (!\str_starts_with($line, 'data: ')) {
                continue;
            }

            $data = \json_decode(\substr($line, 6), true, 512, \JSON_THROW_ON_ERROR);
            $type = $data['type'] ?? '';

            match ($type) {
                'content_block_start' => (function () use ($data, $onChunk, &$currentToolUse, &$currentToolJson, &$contentBlocks): void {
                    $block = $data['content_block'] ?? [];
                    if (($block['type'] ?? '') === 'tool_use') {
                        $currentToolUse = new ToolUseBlock(
                            id: $block['id'],
                            name: $block['name'],
                            input: [],
                        );
                        $currentToolJson = '';
                        $onChunk(new StreamChunk(type: 'tool_use_start', toolUse: $currentToolUse));
                    }
                })(),
                'content_block_delta' => (function () use ($data, $onChunk, &$currentToolJson): void {
                    $delta = $data['delta'] ?? [];
                    $deltaType = $delta['type'] ?? '';
                    if ($deltaType === 'text_delta') {
                        $onChunk(new StreamChunk(type: 'text_delta', text: $delta['text'] ?? ''));
                    } elseif ($deltaType === 'input_json_delta') {
                        $currentToolJson .= $delta['partial_json'] ?? '';
                        $onChunk(new StreamChunk(type: 'tool_use_delta', text: $delta['partial_json'] ?? ''));
                    }
                })(),
                'content_block_stop' => (function () use ($onChunk, &$currentToolUse, &$currentToolJson, &$contentBlocks): void {
                    if ($currentToolUse !== null) {
                        $input = $currentToolJson !== '' ? \json_decode($currentToolJson, true, 512, \JSON_THROW_ON_ERROR) : [];
                        $contentBlocks[] = [
                            'type' => 'tool_use',
                            'id' => $currentToolUse->id,
                            'name' => $currentToolUse->name,
                            'input' => $input,
                        ];
                        $onChunk(new StreamChunk(
                            type: 'tool_use_end',
                            toolUse: new ToolUseBlock(
                                id: $currentToolUse->id,
                                name: $currentToolUse->name,
                                input: $input,
                            ),
                        ));
                        $currentToolUse = null;
                        $currentToolJson = '';
                    }
                })(),
                'message_delta' => (function () use ($data, &$stopReason): void {
                    $stopReason = $data['delta']['stop_reason'] ?? $stopReason;
                })(),
                'message_stop' => (function () use ($onChunk): void {
                    $onChunk(new StreamChunk(type: 'message_stop'));
                })(),
                default => null,
            };
        }

        return ['content' => $contentBlocks, 'stop_reason' => $stopReason];
    }

    /**
     * @return array<string, mixed>
     */
    private function httpPost(string $url, array $body): array
    {
        $jsonBody = \json_encode($body, \JSON_THROW_ON_ERROR);

        $ch = \curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL.');
        }

        \curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $jsonBody,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            \CURLOPT_TIMEOUT => 120,
        ]);

        $responseBody = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            $error = \curl_error($ch);
            \curl_close($ch);
            throw new \RuntimeException("cURL error: {$error}");
        }

        \curl_close($ch);

        if (!\is_string($responseBody)) {
            throw new \RuntimeException('Unexpected cURL response type.');
        }

        /** @var array<string, mixed> $data */
        $data = \json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);

        if ($httpCode === 429) {
            $retryAfter = (int) ($data['error']['retry_after'] ?? 60);
            throw new RateLimitException($retryAfter, $data['error']['message'] ?? 'Rate limited');
        }

        if ($httpCode >= 400) {
            $errorMessage = $data['error']['message'] ?? "HTTP {$httpCode}";
            throw new \RuntimeException("Anthropic API error: {$errorMessage}");
        }

        return $data;
    }

    /**
     * @return MessageResponse
     */
    private function httpPostStreaming(string $url, array $body, callable $onChunk): MessageResponse
    {
        $jsonBody = \json_encode($body, \JSON_THROW_ON_ERROR);

        $ch = \curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize cURL.');
        }

        $buffer = '';
        $allLines = [];
        $fullText = '';

        \curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $jsonBody,
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $this->apiKey,
                'anthropic-version: ' . self::API_VERSION,
            ],
            \CURLOPT_TIMEOUT => 300,
            \CURLOPT_WRITEFUNCTION => function ($ch, string $data) use (&$buffer, &$allLines, &$fullText, $onChunk): int {
                $buffer .= $data;

                while (($pos = \strpos($buffer, "\n")) !== false) {
                    $line = \rtrim(\substr($buffer, 0, $pos));
                    $buffer = \substr($buffer, $pos + 1);

                    $allLines[] = $line;

                    // Process inline for text deltas (low latency)
                    if (\str_starts_with($line, 'data: ')) {
                        $decoded = \json_decode(\substr($line, 6), true);
                        if (($decoded['type'] ?? '') === 'content_block_delta'
                            && ($decoded['delta']['type'] ?? '') === 'text_delta') {
                            $text = $decoded['delta']['text'] ?? '';
                            $fullText .= $text;
                            $onChunk(new StreamChunk(type: 'text_delta', text: $text));
                        }
                    }
                }

                return \strlen($data);
            },
        ]);

        \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        \curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException("Anthropic API streaming error: HTTP {$httpCode}");
        }

        // Parse all events for tool use blocks and final state.
        // Forward non-text-delta chunks (tool_use_start/delta/end, message_stop)
        // to the real $onChunk — text deltas were already forwarded inline above.
        $parsed = $this->parseSseEvents($allLines, function (StreamChunk $chunk) use ($onChunk): void {
            if ($chunk->type !== 'text_delta') {
                $onChunk($chunk);
            }
        });

        $content = $parsed['content'];
        if ($fullText !== '') {
            \array_unshift($content, ['type' => 'text', 'text' => $fullText]);
        }

        return new MessageResponse(
            content: $content,
            stopReason: $parsed['stop_reason'],
        );
    }
}
