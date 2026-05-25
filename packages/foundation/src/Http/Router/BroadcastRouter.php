<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class BroadcastRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
        private readonly ?string $subscribersJsonPath = null,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'broadcast';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $broadcastStorage = $ctx->broadcastStorage;
        $channels = self::parseChannels($ctx->query['channels'] ?? 'admin');
        if ($channels === []) {
            $channels = ['admin'];
        }
        $logger = $this->logger ?? new NullLogger();

        $initialCursor = self::resolveInitialCursor($request, $broadcastStorage->maxId($channels));

        // Subscriber tracking (M5D WP01 — additive extension).
        // Build a stable, non-secret connectionId from timing + PID.
        $connectedSince = microtime(true);
        $connectionId = substr(hash('sha256', $connectedSince . ':' . getmypid()), 0, 16);

        // Resolve the account from the request attribute set by SessionMiddleware.
        // Falls back to accountId=0 (anonymous) when no account is present.
        $account = $request->attributes->get('_account');
        $accountId = 0;
        $accountLabel = null;
        if (is_object($account) && method_exists($account, 'id')) {
            $accountId = (int) $account->id();
        }
        if (is_object($account) && method_exists($account, 'label')) {
            $accountLabel = (string) $account->label();
            if ($accountLabel === '') {
                $accountLabel = null;
            }
        }

        $subscribersPath = $this->subscribersJsonPath;

        // Register this connection in subscribers.json (best-effort; never fatal).
        if ($subscribersPath !== null) {
            try {
                $this->appendSubscriber($subscribersPath, [
                    'accountId' => $accountId,
                    'accountLabel' => $accountLabel,
                    'channels' => $channels,
                    'connectedSince' => $connectedSince,
                    'lastHeartbeat' => $connectedSince,
                    'connectionId' => $connectionId,
                ]);
            } catch (\Throwable $e) {
                $logger->error(sprintf('BroadcastRouter: failed to register subscriber: %s', $e->getMessage()));
            }

            // On shutdown: remove this entry atomically.
            register_shutdown_function(function () use ($subscribersPath, $connectionId, $logger): void {
                try {
                    $this->removeSubscriber($subscribersPath, $connectionId);
                } catch (\Throwable $e) {
                    $logger->error(sprintf('BroadcastRouter: failed to remove subscriber on shutdown: %s', $e->getMessage()));
                }
            });
        }

        return new StreamedResponse(function () use (
            $broadcastStorage,
            $channels,
            $logger,
            $initialCursor,
            $subscribersPath,
            $connectionId,
        ): void {
            echo "event: connected\ndata: " . json_encode(['channels' => $channels], JSON_THROW_ON_ERROR) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            $cursor = $initialCursor;
            $lastKeepalive = time();

            while (connection_aborted() === 0) {
                try {
                    $messages = $broadcastStorage->poll($cursor, $channels);
                } catch (\Throwable $e) {
                    $logger->error(sprintf('SSE poll error: %s', $e->getMessage()));
                    echo "event: error\ndata: " . json_encode(['message' => 'Broadcast poll failed'], JSON_THROW_ON_ERROR) . "\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    usleep(5_000_000);
                    continue;
                }

                foreach ($messages as $msg) {
                    $cursor = $msg['id'];
                    try {
                        // Emit `id:` so EventSource sends Last-Event-ID on reconnect,
                        // letting the resume path above pick up from this exact point.
                        $frame = sprintf(
                            "id: %d\nevent: %s\ndata: %s\n\n",
                            $msg['id'],
                            $msg['event'],
                            json_encode($msg, JSON_THROW_ON_ERROR),
                        );
                        echo $frame;
                    } catch (\JsonException $e) {
                        $logger->error(sprintf('SSE json_encode error for event %s: %s', $msg['event'], $e->getMessage()));
                    }
                }

                if ($messages !== []) {
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }

                if ((time() - $lastKeepalive) >= 15) {
                    echo ": keepalive\n\n";
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                    $lastKeepalive = time();

                    // Update heartbeat in subscribers.json (best-effort).
                    if ($subscribersPath !== null) {
                        try {
                            $this->updateHeartbeat($subscribersPath, $connectionId);
                        } catch (\Throwable $e) {
                            $logger->error(sprintf('BroadcastRouter: failed to update subscriber heartbeat: %s', $e->getMessage()));
                        }
                    }
                }

                usleep(500_000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param array{accountId: int, accountLabel: string|null, channels: list<string>, connectedSince: float, lastHeartbeat: float, connectionId: string} $entry
     */
    private function appendSubscriber(string $jsonPath, array $entry): void
    {
        $this->rewriteSubscribers($jsonPath, static function (array $rows) use ($entry): array {
            $rows[] = $entry;
            return $rows;
        });
    }

    private function updateHeartbeat(string $jsonPath, string $connectionId): void
    {
        $now = microtime(true);
        $this->rewriteSubscribers($jsonPath, static function (array $rows) use ($connectionId, $now): array {
            foreach ($rows as &$row) {
                if (isset($row['connectionId']) && $row['connectionId'] === $connectionId) {
                    $row['lastHeartbeat'] = $now;
                }
            }
            unset($row);
            return $rows;
        });
    }

    private function removeSubscriber(string $jsonPath, string $connectionId): void
    {
        $this->rewriteSubscribers($jsonPath, static function (array $rows) use ($connectionId): array {
            return array_values(array_filter(
                $rows,
                static fn(array $r): bool => !isset($r['connectionId']) || $r['connectionId'] !== $connectionId,
            ));
        });
    }

    /**
     * Atomic read-modify-write on subscribers.json.
     *
     * Uses write-to-temp-then-rename per CLAUDE.md atomic-file-write rule.
     *
     * @param callable(array<int, array<string, mixed>>): array<int, array<string, mixed>> $mutate
     */
    private function rewriteSubscribers(string $jsonPath, callable $mutate): void
    {
        $dir = dirname($jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $existing = [];
        if (is_file($jsonPath)) {
            try {
                $raw = file_get_contents($jsonPath);
                if ($raw !== false && $raw !== '') {
                    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                    if (is_array($decoded)) {
                        $existing = $decoded;
                    }
                }
            } catch (\JsonException) {
                // Malformed file — start fresh
            }
        }

        $updated = $mutate($existing);

        $tmp = $jsonPath . '.tmp.' . getmypid();
        file_put_contents($tmp, json_encode(array_values($updated), JSON_THROW_ON_ERROR));
        rename($tmp, $jsonPath);
    }

    /**
     * Resolve the starting cursor for a new SSE connection.
     *
     * Resume from the EventSource `Last-Event-ID` header when the client sent
     * one (auto-reconnect path) so no events are missed. Otherwise begin at
     * the supplied high-water mark — new connections do NOT receive history.
     */
    public static function resolveInitialCursor(Request $request, int $highWaterMark): int
    {
        $lastEventId = $request->headers->get('Last-Event-ID');
        if ($lastEventId !== null && ctype_digit($lastEventId)) {
            return (int) $lastEventId;
        }

        return $highWaterMark;
    }

    /**
     * @return list<string>
     */
    private static function parseChannels(string $channelsParam): array
    {
        if ($channelsParam === '') {
            return [];
        }

        $channels = array_map('trim', explode(',', $channelsParam));

        return array_values(array_filter($channels, static fn(string $ch): bool => $ch !== ''));
    }
}
