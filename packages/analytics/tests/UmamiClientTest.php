<?php

declare(strict_types=1);

namespace Waaseyaa\Analytics\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Analytics\Transport;
use Waaseyaa\Analytics\UmamiClient;

#[CoversClass(UmamiClient::class)]
final class UmamiClientTest extends TestCase
{
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns a spy Transport whose last call details are stored in public
     * properties $lastUrl and $lastBody (null when never called).
     */
    private function makeSpyTransport(string|false $response = '{"ok":true}'): Transport
    {
        return new class ($response) implements Transport {
            public string|null $lastUrl  = null;
            public string|null $lastBody = null;

            public function __construct(private readonly string|false $response) {}

            public function post(string $url, string $body): string|false
            {
                $this->lastUrl  = $url;
                $this->lastBody = $body;
                return $this->response;
            }
        };
    }

    /**
     * Returns a spy logger \Closure plus a reference to the collected messages.
     * Each entry: ['message' => string, 'context' => array].
     *
     * @param list<array{message:string,context:array<mixed>}> $messages (out-ref)
     */
    private function makeSpyLogger(array &$messages): \Closure
    {
        return static function (string $message, array $context = []) use (&$messages): void {
            $messages[] = ['message' => $message, 'context' => $context];
        };
    }

    // -----------------------------------------------------------------------
    // Payload shape
    // -----------------------------------------------------------------------

    #[Test]
    public function payload_shape_matches_umami_contract(): void
    {
        $spy = $this->makeSpyTransport();

        $client = new UmamiClient(
            trackerUrl: 'https://umami.example.com',
            siteId: 'abc123',
            appUrl: 'https://myapp.org/path',
            transport: $spy,
        );
        $client->send('page_view', ['key' => 'val'], '/about');

        $this->assertSame('https://umami.example.com/api/send', $spy->lastUrl);

        $payload = json_decode((string) $spy->lastBody, true);
        $this->assertIsArray($payload);
        $this->assertSame('event', $payload['type']);

        $inner = $payload['payload'];
        $this->assertSame('myapp.org', $inner['hostname']);
        $this->assertSame('abc123', $inner['website']);
        $this->assertSame('page_view', $inner['name']);
        $this->assertSame(['key' => 'val'], $inner['data']);
        $this->assertSame('/about', $inner['url']);
        $this->assertSame('en', $inner['language']);
        $this->assertSame('', $inner['referrer']);
        $this->assertSame('', $inner['screen']);
        $this->assertSame('', $inner['title']);
    }

    #[Test]
    public function tracker_url_trailing_slash_is_stripped(): void
    {
        $spy = $this->makeSpyTransport();

        $client = new UmamiClient(
            trackerUrl: 'https://umami.example.com/',
            siteId: 'site1',
            appUrl: 'https://myapp.org',
            transport: $spy,
        );
        $client->send('hit');

        $this->assertSame('https://umami.example.com/api/send', $spy->lastUrl);
    }

    // -----------------------------------------------------------------------
    // Hostname parsing
    // -----------------------------------------------------------------------

    #[Test]
    public function hostname_is_extracted_from_app_url_with_path(): void
    {
        $spy = $this->makeSpyTransport();

        $client = new UmamiClient(
            trackerUrl: 'https://umami.example.com',
            siteId: 's1',
            appUrl: 'https://example.org/some/path',
            transport: $spy,
        );
        $client->send('test');

        $payload = json_decode((string) $spy->lastBody, true);
        $this->assertSame('example.org', $payload['payload']['hostname']);
    }

    #[Test]
    public function bare_host_falls_back_when_parse_url_returns_no_host(): void
    {
        $spy = $this->makeSpyTransport();

        // A bare host string with no scheme — parse_url(PHP_URL_HOST) returns null.
        $client = new UmamiClient(
            trackerUrl: 'https://umami.example.com',
            siteId: 's1',
            appUrl: 'mysite.example',
            transport: $spy,
        );
        $client->send('test');

        $payload = json_decode((string) $spy->lastBody, true);
        $this->assertSame('mysite.example', $payload['payload']['hostname']);
    }

    // -----------------------------------------------------------------------
    // No-op / misconfig paths
    // -----------------------------------------------------------------------

    #[Test]
    public function empty_tracker_url_does_not_call_transport_and_logs(): void
    {
        $spy      = $this->makeSpyTransport();
        $messages = [];
        $logger   = $this->makeSpyLogger($messages);

        $client = new UmamiClient(
            trackerUrl: '',
            siteId: 'site1',
            appUrl: 'https://myapp.org',
            transport: $spy,
            logger: $logger,
        );
        $client->send('hit');

        $this->assertNull($spy->lastUrl, 'Transport must not be called when trackerUrl is empty');
        $this->assertNotEmpty($messages, 'Logger must be called with a misconfig message');
        $this->assertStringContainsString('misconfig', strtolower($messages[0]['message']));
    }

    #[Test]
    public function empty_site_id_does_not_call_transport_and_logs(): void
    {
        $spy      = $this->makeSpyTransport();
        $messages = [];
        $logger   = $this->makeSpyLogger($messages);

        $client = new UmamiClient(
            trackerUrl: 'https://umami.example.com',
            siteId: '',
            appUrl: 'https://myapp.org',
            transport: $spy,
            logger: $logger,
        );
        $client->send('hit');

        $this->assertNull($spy->lastUrl, 'Transport must not be called when siteId is empty');
        $this->assertNotEmpty($messages, 'Logger must be called with a misconfig message');
        $this->assertStringContainsString('misconfig', strtolower($messages[0]['message']));
    }

    #[Test]
    public function send_is_void_and_does_not_throw_on_misconfig(): void
    {
        // No explicit transport/logger: must not throw even with the default StreamTransport.
        $client = new UmamiClient(
            trackerUrl: '',
            siteId: '',
            appUrl: 'https://myapp.org',
        );
        // send() returns void (null in PHP return-value position).
        $result = $client->send('hit');
        $this->assertNull($result);
    }

    // -----------------------------------------------------------------------
    // Failure path (transport returns false or throws)
    // -----------------------------------------------------------------------

    #[Test]
    public function transport_failure_is_logged_and_send_does_not_throw(): void
    {
        $spy      = $this->makeSpyTransport(false);
        $messages = [];
        $logger   = $this->makeSpyLogger($messages);

        $client = new UmamiClient(
            trackerUrl: 'https://umami.example.com',
            siteId: 'site1',
            appUrl: 'https://myapp.org',
            transport: $spy,
            logger: $logger,
        );

        // Must not throw even when transport returns false.
        $client->send('hit');

        $this->assertNotEmpty($messages, 'Logger must be called when transport returns false');
        $this->assertStringContainsString('fail', strtolower($messages[0]['message']));
    }

    #[Test]
    public function transport_exception_is_caught_and_logged_and_send_does_not_throw(): void
    {
        $throwingTransport = new class implements Transport {
            public function post(string $url, string $body): string|false
            {
                throw new \RuntimeException('network down');
            }
        };
        $messages = [];
        $logger   = $this->makeSpyLogger($messages);

        $client = new UmamiClient(
            trackerUrl: 'https://umami.example.com',
            siteId: 'site1',
            appUrl: 'https://myapp.org',
            transport: $throwingTransport,
            logger: $logger,
        );

        // Must not re-throw.
        $client->send('hit');

        $this->assertNotEmpty($messages, 'Logger must be called when transport throws');
    }

    // -----------------------------------------------------------------------
    // BC: existing callers without transport/logger still work
    // -----------------------------------------------------------------------

    #[Test]
    public function existing_three_arg_constructor_is_still_valid(): void
    {
        // Confirms BC: the three original positional args remain sufficient.
        // A real StreamTransport would attempt file_get_contents, so we just
        // verify the object is constructable — the misconfig-path tests above
        // fully exercise send() via the spy.
        $client = new UmamiClient(
            trackerUrl: 'https://umami.example.com',
            siteId: 'site1',
            appUrl: 'https://myapp.org',
        );
        $this->assertInstanceOf(UmamiClient::class, $client);
    }
}
