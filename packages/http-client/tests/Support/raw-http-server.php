#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Exact-byte HTTP responder for transport tests that php -S cannot express
 * (declared Content-Length larger than the payload, chunked transfer).
 */

$listen = getenv('WAASEYAA_RAW_LISTEN');
$payload = getenv('WAASEYAA_RAW_HTTP');
if (!is_string($listen) || $listen === '' || !is_string($payload) || $payload === '') {
    fwrite(STDERR, "raw-http-server: WAASEYAA_RAW_LISTEN and WAASEYAA_RAW_HTTP are required\n");
    exit(1);
}

$server = stream_socket_server('tcp://' . $listen, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, "raw-http-server: {$errstr}\n");
    exit(1);
}

fwrite(STDERR, "ready\n");
fflush(STDERR);

$connection = @stream_socket_accept($server, 10);
if ($connection === false) {
    fclose($server);
    exit(1);
}

stream_set_timeout($connection, 2);
$request = '';
while (!str_contains($request, "\r\n\r\n")) {
    $chunk = fread($connection, 8192);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $request .= $chunk;
}

$splitAt = (int) (getenv('WAASEYAA_RAW_SPLIT_AT') ?: '0');
$delayUs = (int) (getenv('WAASEYAA_RAW_DELAY_US') ?: '0');
if ($splitAt > 0 && $splitAt < strlen($payload) && $delayUs > 0) {
    fwrite($connection, substr($payload, 0, $splitAt));
    fflush($connection);
    usleep($delayUs);
    fwrite($connection, substr($payload, $splitAt));
} else {
    fwrite($connection, $payload);
}
fclose($connection);
fclose($server);
