<?php

declare(strict_types=1);

/**
 * Child process for {@see \Waaseyaa\AI\Agent\Tests\Support\StallingTransportServer}.
 *
 * A raw TCP peer, not an HTTP server: the `silent` mode has to be able to accept
 * a connection and then never write a byte, which no real HTTP server will do.
 *
 * Binds its own ephemeral port and announces it on stdout as `READY <port>`, so
 * the parent can never probe a port that some other (dying) peer still owns.
 *
 * Usage: php stalling-transport-peer.php <mode> <lifetimeSeconds>
 */

$mode = (string) ($argv[1] ?? 'silent');
$lifetime = (float) ($argv[2] ?? 40.0);

$server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "bind failed: {$errstr} ({$errno})\n");
    exit(1);
}

$address = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, 'READY ' . substr($address, (int) strrpos($address, ':') + 1) . "\n");

$sseHeaders = "HTTP/1.1 200 OK\r\n"
    . "Content-Type: text/event-stream\r\n"
    . "Cache-Control: no-cache\r\n"
    . "Connection: close\r\n"
    . "\r\n";

$firstEvents = "event: message_start\n"
    . 'data: {"type":"message_start","message":{"id":"msg_stall"}}' . "\n\n"
    . "event: content_block_delta\n"
    . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":"Hello"}}' . "\n\n";

$remainingEvents = "event: content_block_delta\n"
    . 'data: {"type":"content_block_delta","index":0,"delta":{"type":"text_delta","text":" world"}}' . "\n\n"
    . "event: message_delta\n"
    . 'data: {"type":"message_delta","delta":{"stop_reason":"end_turn"}}' . "\n\n"
    . "event: message_stop\n"
    . 'data: {"type":"message_stop"}' . "\n\n";

$jsonBody = '{"content":[{"type":"text","text":"Hello world"}],"stop_reason":"end_turn",'
    . '"usage":{"input_tokens":7,"output_tokens":3}}';

/**
 * @param resource $connection
 * @return bool whether the connection is finished and can be closed
 */
$respond = static function ($connection) use ($mode, $sseHeaders, $firstEvents, $remainingEvents, $jsonBody): bool {
    switch ($mode) {
        case 'silent':
            // Accept and say nothing, ever. Over https:// this stalls the TLS
            // handshake, so the transfer never leaves the connection phase.
            return false;

        case 'stall':
            // Answer, emit one text delta, then go quiet forever: the caller's
            // in-callback budget fires once and can never fire again.
            @fwrite($connection, $sseHeaders . $firstEvents);

            return false;

        case 'sse':
            @fwrite($connection, $sseHeaders . $firstEvents . $remainingEvents);

            return true;

        case 'json':
            @fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: "
                . \strlen($jsonBody) . "\r\nConnection: close\r\n\r\n" . $jsonBody);

            return true;

        default:
            return true;
    }
};

$deadline = \microtime(true) + $lifetime;

/** @var list<array{handle: resource, request: string, answered: bool}> $connections */
$connections = [];

while (\microtime(true) < $deadline) {
    $accepted = @stream_socket_accept($server, 0.05);
    if (\is_resource($accepted)) {
        stream_set_blocking($accepted, false);
        $connections[] = ['handle' => $accepted, 'request' => '', 'answered' => false];
    }

    foreach ($connections as $index => $connection) {
        if ($connection['answered']) {
            continue;
        }

        $chunk = @fread($connection['handle'], 65536);
        if (\is_string($chunk) && $chunk !== '') {
            $connections[$index]['request'] .= $chunk;
        } elseif (feof($connection['handle'])) {
            // Readiness probe (or an abandoned client): drop it.
            @fclose($connection['handle']);
            unset($connections[$index]);
            continue;
        }

        if (!\str_contains($connections[$index]['request'], "\r\n\r\n")) {
            continue;
        }

        $connections[$index]['answered'] = true;
        if ($respond($connection['handle'])) {
            @fclose($connection['handle']);
            unset($connections[$index]);
        }
    }

    usleep(5_000);
}

foreach ($connections as $connection) {
    @fclose($connection['handle']);
}
fclose($server);
