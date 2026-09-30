<?php

declare(strict_types=1);

// Synthetic loopback HTTP peer. Self-expires even if its parent disappears.
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    throw new RuntimeException('Cannot bind embedding test peer.');
}
$address = (string) stream_socket_get_name($server, false);
echo 'READY ' . substr($address, (int) strrpos($address, ':') + 1) . "\n";
flush();
$connection = @stream_socket_accept($server, 8);
if ($connection === false) {
    exit(1);
}
stream_set_timeout($connection, 2);
$request = '';
while (!str_contains($request, "\r\n\r\n")) {
    $chunk = fread($connection, 8192);
    if ($chunk === false || $chunk === '' || strlen($request) > 65536) {
        exit(2);
    }
    $request .= $chunk;
}
[$headers, $body] = explode("\r\n\r\n", $request, 2);
preg_match('/Content-Length: (\d+)/i', $headers, $matches);
$length = (int) ($matches[1] ?? 0);
while (strlen($body) < $length) {
    $chunk = fread($connection, $length - strlen($body));
    if ($chunk === false || $chunk === '') {
        exit(3);
    }
    $body .= $chunk;
}
echo json_encode(['headers' => $headers, 'body' => $body], JSON_THROW_ON_ERROR) . "\n";
flush();
$mode = $argv[1];
if ($mode === 'silent' || $mode === 'body-stall') {
    if ($mode === 'body-stall') {
        fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 200\r\n\r\n{");
        fflush($connection);
    }
    usleep(4_000_000);
} elseif ($mode === 'trickle') {
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Length: 20\r\n\r\n");
    for ($i = 0; $i < 12; ++$i) {
        @fwrite($connection, ' ');
        fflush($connection);
        usleep(300_000);
    }
} else {
    if ($mode === 'slow-success') {
        usleep(2_200_000);
    }
    $status = $mode === 'error' ? '503 Unavailable' : ($mode === 'redirect' ? '302 Found' : '200 OK');
    $response = $mode === 'invalid' ? '{broken' : ($mode === 'shape' ? '{}' : $argv[2]);
    if ($mode === 'scalar') {
        $response = '42';
    } elseif ($mode === 'oversized') {
        $response = str_repeat(' ', 1048577);
    }
    fwrite($connection, "HTTP/1.1 {$status}\r\nLocation: http://127.0.0.1:1/never\r\nContent-Length: " . strlen($response) . "\r\nConnection: close\r\n\r\n" . $response);
}
fclose($connection);
fclose($server);
