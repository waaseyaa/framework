<?php

declare(strict_types=1);

require dirname(__DIR__, 4) . '/vendor/autoload.php';

use Waaseyaa\Mail\Envelope;
use Waaseyaa\Mail\MailConfiguration;
use Waaseyaa\Mail\Mailer;
use Waaseyaa\Mail\Transport\SmtpTransport;

[$script, $port, $auth, $mode] = $argv;
$config = MailConfiguration::fromArray(['transport' => 'smtp', 'from_address' => 'sender@example.test', 'smtp' => [
    'host' => '127.0.0.1', 'port' => (int) $port, 'username' => 'test-user', 'password' => 'test-secret', 'auth' => $auth,
]]);
$transport = SmtpTransport::fromConfiguration($config);
// Test timing only: exercise Symfony's retained-connection ping immediately.
$smtp = new ReflectionProperty($transport, 'transport')->getValue($transport);
$smtp->setPingThreshold(0);
$mailer = new Mailer($transport, 'sender@example.test');
$results = [];
for ($i = 0; $i < (str_starts_with($mode, 'reconnect-') ? 2 : 1); ++$i) {
    try {
        $mailer->send(new Envelope(['member@example.test'], '', 'Welcome', 'Test message', '<p>Test message</p>'));
        $results[] = ['sent' => true];
    } catch (RuntimeException $exception) {
        $results[] = ['sent' => false, 'error' => $exception->getMessage(), 'previous' => $exception->getPrevious() !== null];
    }
}
$smtp->stop();
echo json_encode($results, JSON_THROW_ON_ERROR), "\n";
