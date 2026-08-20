<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretHandle;

/**
 * OpenAI Chat Completions–compatible HTTP provider (OpenRouter, Azure OpenAI, local gateways, etc.).
 *
 * Implements {@see ProviderInterface} only (no streaming). Text-in / text-out; tool loops should
 * continue to use {@see AnthropicProvider} until tool schema bridging is implemented.
 */
final class OpenAiCompatibleProvider implements ProviderInterface
{
    private const string DEFAULT_BASE = 'https://api.openai.com/v1';

    private readonly SecretHandle $credential;

    /** @var (\Closure(string, array<string, string>, array<string, mixed>): array<string, mixed>)|null */
    private readonly ?\Closure $authenticatedTransport;

    private readonly ProviderTimeouts $timeouts;

    /**
     * @param ProviderTimeouts|null $timeouts bounds for the chat-completion exchange; the default
     *                                        request profile keeps the historical 120s total and
     *                                        adds a connect bound. Ignored when
     *                                        `$authenticatedTransport` replaces cURL.
     */
    public function __construct(
        #[\SensitiveParameter]
        string|SecretHandle $apiKey,
        private readonly string $baseUrl = self::DEFAULT_BASE,
        private readonly string $model = 'gpt-4o-mini',
        ?\Closure $authenticatedTransport = null,
        ?ProviderTimeouts $timeouts = null,
    ) {
        $this->credential = $apiKey instanceof SecretHandle
            ? $apiKey
            : SecretHandle::fromBytes(
                $apiKey,
                SecretClass::ProviderCredential,
                OpenAiCompatibleCredentialOperation::PURPOSE,
                'legacy-static-v1',
                [OpenAiCompatibleCredentialOperation::class],
            );
        $this->authenticatedTransport = $authenticatedTransport;
        $this->timeouts = $timeouts ?? ProviderTimeouts::forRequest();
    }

    public function sendMessage(MessageRequest $request): MessageResponse
    {
        $url = \rtrim($this->baseUrl, '/') . '/chat/completions';
        $fragment = $request->conversation()->toOpenAiChatFragment();
        $body = \array_merge($fragment, ['model' => $this->model]);
        $data = $this->httpPost($url, $body);

        return self::parseChatCompletionResponse($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function parseChatCompletionResponse(array $data): MessageResponse
    {
        $choice = $data['choices'][0] ?? [];
        if (!\is_array($choice)) {
            $choice = [];
        }
        $message = $choice['message'] ?? [];
        if (!\is_array($message)) {
            $message = [];
        }
        $content = $message['content'] ?? '';
        if (!\is_string($content)) {
            $content = \is_array($content) ? \json_encode($content, \JSON_THROW_ON_ERROR) : '';
        }

        $finish = $choice['finish_reason'] ?? 'stop';
        $stopReason = $finish === 'tool_calls' ? 'tool_use' : 'end_turn';

        $usage = $data['usage'] ?? [];
        $usageIn = \is_array($usage) ? (int) ($usage['prompt_tokens'] ?? 0) : 0;
        $usageOut = \is_array($usage) ? (int) ($usage['completion_tokens'] ?? 0) : 0;

        return new MessageResponse(
            content: [['type' => 'text', 'text' => $content]],
            stopReason: $stopReason,
            usage: [
                'input_tokens' => $usageIn,
                'output_tokens' => $usageOut,
            ],
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function httpPost(string $url, array $body): array
    {
        $outcome = $this->credential->consume(new OpenAiCompatibleCredentialOperation(
            fn(array $headers, string $version): ProviderCredentialOutcome => ProviderCredentialOutcome::capture(
                function () use ($url, $headers, $body): array {
                    if ($this->authenticatedTransport !== null) {
                        return ($this->authenticatedTransport)($url, $headers, $body);
                    }

                    return $this->httpPostAuthenticated($url, $body, $headers);
                },
            ),
        ));

        return $outcome->unwrap();
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    private function httpPostAuthenticated(string $url, array $body, array $headers): array
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
            \CURLOPT_HTTPHEADER => self::headerLines($headers),
        ] + $this->timeouts->curlOptions());

        $responseBody = \curl_exec($ch);
        $httpCode = \curl_getinfo($ch, \CURLINFO_HTTP_CODE);

        if ($responseBody === false) {
            $error = \curl_error($ch);
            throw new TransportException("cURL error: {$error}");
        }

        if (!\is_string($responseBody)) {
            throw new TransportException('Unexpected cURL response type.');
        }

        /** @var array<string, mixed> $data */
        $data = \json_decode($responseBody, true, 512, \JSON_THROW_ON_ERROR);

        if ($httpCode === 429) {
            $errorMessage = self::extractOpenAiErrorMessage($data, $httpCode);
            $retryAfter = 60;
            if (\is_array($data['error'] ?? null) && isset($data['error']['retry_after'])) {
                $retryAfter = (int) $data['error']['retry_after'];
            }
            throw new RateLimitException($retryAfter, "OpenAI-compatible API error: {$errorMessage}");
        }

        if ($httpCode >= 500) {
            $errorMessage = self::extractOpenAiErrorMessage($data, $httpCode);
            throw new TransportException("OpenAI-compatible API error: {$errorMessage}");
        }

        if ($httpCode >= 400) {
            $errorMessage = self::extractOpenAiErrorMessage($data, $httpCode);
            throw new ClientErrorException("OpenAI-compatible API error: {$errorMessage}");
        }

        return $data;
    }

    /**
     * @param array<string, string> $headers
     * @return list<string>
     */
    private static function headerLines(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return $lines;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractOpenAiErrorMessage(array $data, int $httpCode): string
    {
        $err = $data['error'] ?? null;
        if (\is_array($err) && isset($err['message'])) {
            return (string) $err['message'];
        }
        if (\is_string($err)) {
            return $err;
        }

        return "HTTP {$httpCode}";
    }
}
