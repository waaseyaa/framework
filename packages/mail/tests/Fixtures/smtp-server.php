<?php

// Disposable loopback server for SMTP contract tests. Never uses real accounts.
declare(strict_types=1);
[$script, $mode, $certificate, $key] = $argv;
$context = stream_context_create(['ssl' => ['local_cert' => $certificate, 'local_pk' => $key]]);
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
echo json_encode(['port' => (int) substr(strrchr(stream_socket_get_name($server, false), ':'), 1)]), "\n";
flush();
$client = stream_socket_accept($server, 10);
$result = ['tls' => false, 'protocol' => null, 'authenticated' => false, 'auth_before_tls' => false, 'mail_seen' => false, 'data' => ''];
if ($client !== false) {
    stream_set_timeout($client, 5);
    fwrite($client, "220 fixture ESMTP\r\n");
    while (($line = fgets($client)) !== false) {
        $command = strtoupper(trim($line));
        if (str_starts_with($command, 'EHLO') || str_starts_with($command, 'HELO')) {
            fwrite($client, "250-fixture\r\n");
            if (!$result['tls'] && $mode !== 'no-tls') {
                fwrite($client, "250-STARTTLS\r\n");
            }
            fwrite($client, match ($mode) {
                'no-auth' => "250 OK\r\n", 'unsupported-auth' => "250 AUTH XOAUTH2\r\n", default => "250 AUTH LOGIN XOAUTH2\r\n",
            });
        } elseif ($command === 'STARTTLS') {
            fwrite($client, "220 Ready for TLS\r\n");
            $result['tls'] = @stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true;
            if (!$result['tls']) {
                break;
            }
            $result['protocol'] = stream_get_meta_data($client)['crypto']['protocol'];
        } elseif ($command === 'AUTH LOGIN') {
            $result['auth_before_tls'] = !$result['tls'];
            fwrite($client, "334 VXNlcm5hbWU6\r\n");
            fgets($client);
            fwrite($client, "334 UGFzc3dvcmQ6\r\n");
            fgets($client);
            $result['authenticated'] = $mode !== 'reject-auth';
            fwrite($client, $result['authenticated'] ? "235 Authenticated\r\n" : "535 Test authentication refused\r\n");
        } elseif (str_starts_with($command, 'AUTH XOAUTH2 ')) {
            $result['auth_before_tls'] = !$result['tls'];
            $result['authenticated'] = true;
            fwrite($client, "235 Authenticated\r\n");
        } elseif (str_starts_with($command, 'MAIL FROM:')) {
            $result['mail_seen'] = true;
            fwrite($client, "250 Sender accepted\r\n");
        } elseif (str_starts_with($command, 'RCPT TO:') || $command === 'RSET' || $command === 'NOOP') {
            fwrite($client, "250 OK\r\n");
        } elseif ($command === 'DATA') {
            fwrite($client, "354 Send message\r\n");
            while (($data = fgets($client)) !== false && $data !== ".\r\n") {
                $result['data'] .= $data;
            }
            fwrite($client, "250 Message accepted\r\n");
        } elseif ($command === 'QUIT') {
            fwrite($client, "221 Bye\r\n");
            break;
        } else {
            fwrite($client, "500 Unsupported command\r\n");
        }
    }
    fclose($client);
}
fclose($server);
echo json_encode($result), "\n";
