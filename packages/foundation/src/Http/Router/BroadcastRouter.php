<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Http\Router;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Api\Controller\BroadcastController;
use Waaseyaa\Foundation\Http\JsonApiResponseTrait;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;

final class BroadcastRouter implements DomainRouterInterface
{
    use JsonApiResponseTrait;

    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->attributes->get('_controller', '') === 'broadcast';
    }

    public function handle(Request $request): Response
    {
        $ctx = WaaseyaaContext::fromRequest($request);
        $broadcastStorage = $ctx->broadcastStorage;
        $channels = BroadcastController::parseChannels($ctx->query['channels'] ?? 'admin');
        if ($channels === []) {
            $channels = ['admin'];
        }
        $logger = $this->logger ?? new NullLogger();

        return new StreamedResponse(function () use ($broadcastStorage, $channels, $logger): void {
            echo "event: connected\ndata: " . json_encode(['channels' => $channels], JSON_THROW_ON_ERROR) . "\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();

            $cursor = null;
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
                    if (isset($msg['cursor'])) {
                        $cursor = $msg['cursor'];
                    }
                    try {
                        $frame = "event: {$msg['event']}\ndata: " . json_encode($msg, JSON_THROW_ON_ERROR) . "\n\n";
                        echo $frame;
                    } catch (\JsonException $e) {
                        $logger->error(sprintf('SSE json_encode error for event %s: %s', $msg['event'] ?? 'unknown', $e->getMessage()));
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
}
