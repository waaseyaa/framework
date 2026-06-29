<?php

declare(strict_types=1);

namespace Waaseyaa\Analytics;

/**
 * Fire-and-forget backend Umami event sender.
 *
 * Synchronous with a short timeout — no-op when not configured.
 *
 * The optional $transport parameter (Transport seam) defaults to
 * StreamTransport and may be replaced in tests or custom deployments
 * without any dependency on waaseyaa/foundation.
 *
 * The optional $logger parameter accepts a nullable \Closure with the
 * signature `(string $message, array $context = []): void`. When provided,
 * it receives a message on both the misconfig early-return path and any
 * failed-send / transport-exception path. When null (default), failures
 * are silently ignored — the same behaviour as before the seam was added.
 *
 * The optional $language parameter sets the BCP-47 locale tag reported in the
 * Umami payload (defaults to 'en'). Set it to the application's active locale
 * so analytics reflects the language of each event correctly.
 *
 * Example logger wiring (no external dependency needed):
 *   $client = new UmamiClient($url, $id, $app, logger: function(string $m): void {
 *       error_log('[analytics] ' . $m);
 *   });
 *
 * @api
 */
final class UmamiClient
{
    private readonly string $hostname;
    private readonly Transport $transport;

    public function __construct(
        private readonly string $trackerUrl,
        private readonly string $siteId,
        string $appUrl,
        ?Transport $transport = null,
        private readonly ?\Closure $logger = null,
        private readonly string $language = 'en',
    ) {
        $host = parse_url($appUrl, PHP_URL_HOST);
        $this->hostname  = is_string($host) && $host !== '' ? $host : $appUrl;
        $this->transport = $transport ?? new StreamTransport();
    }

    public function send(string $event, array $data = [], string $url = '/'): void
    {
        if ($this->trackerUrl === '' || $this->siteId === '') {
            $this->log('UmamiClient misconfig: trackerUrl and siteId must both be non-empty; event dropped.', [
                'event'       => $event,
                'tracker_url' => $this->trackerUrl,
                'site_id'     => $this->siteId,
            ]);
            return;
        }

        $payload = json_encode([
            'payload' => [
                'hostname' => $this->hostname,
                'language' => $this->language,
                'referrer' => '',
                'screen'   => '',
                'title'    => '',
                'url'      => $url,
                'website'  => $this->siteId,
                'name'     => $event,
                'data'     => $data,
            ],
            'type' => 'event',
        ]);

        if ($payload === false) {
            $this->log('UmamiClient: failed to JSON-encode event payload; event dropped.', ['event' => $event]);
            return;
        }

        try {
            $result = $this->transport->post(
                rtrim($this->trackerUrl, '/') . '/api/send',
                $payload,
            );
        } catch (\Throwable $e) {
            $this->log('UmamiClient: transport threw an exception sending event; event dropped.', [
                'event'     => $event,
                'exception' => $e->getMessage(),
            ]);
            return;
        }

        if ($result === false) {
            $this->log('UmamiClient: transport failed (returned false) sending event; event dropped.', [
                'event' => $event,
            ]);
        }
    }

    private function log(string $message, array $context = []): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message, $context);
        }
    }
}
