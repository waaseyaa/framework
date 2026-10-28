<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SmtpSubmissionTest extends TestCase
{
    #[DataProvider('submissions')]
    public function test_verified_starttls_authentication_and_fail_closed_submission(string $mode, string $auth, bool $trusted, bool $success): void
    {
        $directory = sys_get_temp_dir() . '/waaseyaa-smtp-' . bin2hex(random_bytes(8));
        mkdir($directory, 0o700);
        $config = $directory . '/openssl.cnf';
        $certificateIp = $mode === 'wrong-name' ? '127.0.0.2' : '127.0.0.1';
        file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=v3\n[dn]\n[v3]\nsubjectAltName=IP:$certificateIp\nbasicConstraints=critical,CA:TRUE\n");
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr = openssl_csr_new(['commonName' => $certificateIp], $key, ['config' => $config, 'digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['config' => $config, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256']);
        openssl_x509_export_to_file($certificate, $directory . '/cert.pem');
        openssl_pkey_export_to_file($key, $directory . '/key.pem');
        $process = proc_open([PHP_BINARY, __DIR__ . '/../Fixtures/smtp-server.php', $mode, $directory . '/cert.pem', $directory . '/key.pem'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $mailer = null;
        try {
            $port = json_decode(fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR)['port'];
            // OpenSSL trust is set before PHP starts, independently of cached
            // SSL_CERT_FILE state in the long-running PHPUnit process.
            $command = [PHP_BINARY];
            if ($trusted) {
                $command[] = '-d';
                $command[] = 'openssl.cafile=' . $directory . '/cert.pem';
            }
            array_push($command, __DIR__ . '/../Fixtures/smtp-client.php', (string) $port, $auth, $mode);
            $client = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $clientPipes);
            fclose($clientPipes[0]);
            $output = stream_get_contents($clientPipes[1]);
            $error = stream_get_contents($clientPipes[2]);
            fclose($clientPipes[1]);
            fclose($clientPipes[2]);
            self::assertSame(0, proc_close($client), $error);
            $attempts = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            $last = $attempts[array_key_last($attempts)];
            self::assertSame($success, $last['sent']);
            if (!$success) {
                self::assertSame('SMTP delivery failed.', $last['error']);
                self::assertFalse($last['previous']);
            }
            $connections = json_decode(fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
            if (str_starts_with($mode, 'reconnect-')) {
                self::assertCount(2, $attempts);
                self::assertTrue($attempts[0]['sent']);
                self::assertTrue($connections[0]['authenticated']);
            }
            $result = $connections[array_key_last($connections)];
            self::assertFalse($result['auth_before_tls']);
            self::assertSame($success, $result['mail_seen']);
            if ($success) {
                self::assertTrue($result['tls']);
                self::assertContains($result['protocol'], ['TLSv1.2', 'TLSv1.3']);
                self::assertTrue($result['authenticated']);
                self::assertStringContainsString('multipart/alternative', $result['data']);
                self::assertStringContainsString('Subject: Welcome', $result['data']);
            }
        } finally {
            $mailer = null;
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
            foreach (glob($directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public static function submissions(): array
    {
        return [
            'Reconnected AUTH required' => ['reconnect-no-auth', 'login', true, false],
            'Reconnected EHLO downgrade refused' => ['reconnect-helo', 'login', true, false],
            'Initial EHLO downgrade refused' => ['helo', 'login', true, false],
            'LOGIN' => ['ok', 'login', true, true],
            'XOAUTH2' => ['ok', 'xoauth2', true, true],
            'STARTTLS required' => ['no-tls', 'login', true, false],
            'Authentication required' => ['no-auth', 'login', true, false],
            'Authentication refused' => ['reject-auth', 'login', true, false],
            'Certificate verification' => ['ok', 'login', false, false],
            'Certificate name verification' => ['wrong-name', 'login', true, false],
            'No alternative authenticator' => ['unsupported-auth', 'login', true, false],
        ];
    }
}
