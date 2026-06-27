<?php

declare(strict_types=1);

namespace Waaseyaa\Analytics;

/**
 * Default Transport implementation using PHP stream wrappers.
 *
 * Moves the exact `stream_context_create` + `file_get_contents` behaviour
 * that was formerly inlined in UmamiClient::send() behind the Transport seam,
 * making the client testable without network I/O. The @ error-suppressor is
 * dropped here — errors are surfaced as a false return (file_get_contents
 * returns false on failure) and logged by UmamiClient via the optional logger
 * sink.
 */
final class StreamTransport implements Transport
{
    public function post(string $url, string $body): string|false
    {
        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nUser-Agent: waaseyaa-server/1.0",
                'content'       => $body,
                'timeout'       => 2,
                'ignore_errors' => true,
            ],
        ]);

        return file_get_contents($url, false, $context);
    }
}
