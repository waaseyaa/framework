<?php

declare(strict_types=1);

namespace Waaseyaa\Mail\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailConfiguration;
use Waaseyaa\Mail\Mailer;
use Waaseyaa\Mail\Transport\SmtpTransport;

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
        $oldCa = getenv('SSL_CERT_FILE');
        putenv($trusted ? 'SSL_CERT_FILE=' . $directory . '/cert.pem' : 'SSL_CERT_FILE');
        $process = proc_open([PHP_BINARY, __DIR__ . '/../Fixtures/smtp-server.php', $mode, $directory . '/cert.pem', $directory . '/key.pem'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $mailer = null;
        try {
            $port = json_decode(fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR)['port'];
            $settings = MailConfiguration::fromArray(['transport' => 'smtp', 'from_address' => 'sender@example.test', 'smtp' => [
                'host' => '127.0.0.1', 'port' => $port, 'username' => 'test-user', 'password' => 'test-secret', 'auth' => $auth,
            ]]);
            $mailer = new Mailer(SmtpTransport::fromConfiguration($settings), 'sender@example.test');
            $failure = null;
            try {
                $mailer->send(new Envelope(['member@example.test'], '', 'Welcome', 'Test message', '<p>Test message</p>'));
            } catch (\RuntimeException $error) {
                $failure = $error;
            }
            self::assertSame($success, $failure === null);
            if ($failure !== null) {
                self::assertSame('SMTP delivery failed.', $failure->getMessage());
                self::assertNull($failure->getPrevious());
            }
            $mailer = null;
            $result = json_decode(fgets($pipes[1]), true, flags: JSON_THROW_ON_ERROR);
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
            putenv($oldCa === false ? 'SSL_CERT_FILE' : 'SSL_CERT_FILE=' . $oldCa);
            foreach (glob($directory . '/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    public static function submissions(): array
    {
        return [
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
