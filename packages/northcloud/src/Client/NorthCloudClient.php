<?php

declare(strict_types=1);

namespace Waaseyaa\NorthCloud\Client;

use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\Log\NullLogger;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\StreamHttpClient;

/**
 * HTTP client for the North Cloud REST API.
 *
 * Read endpoints (search, people, band office, dictionary) are unauthenticated.
 * Write endpoints (crawl jobs, link-sources) require a bearer token.
 *
 * Pass an optional HttpClientInterface for testing; the default is a
 * StreamHttpClient (no external HTTP client dependency). Non-2xx responses
 * are treated as failures: they are never returned as success and never
 * written to the cache.
 * @api
 */
final class NorthCloudClient
{
    /** Attribution string matching the NC API X-Attribution header. */
    public const string DICTIONARY_ATTRIBUTION = "Ojibwe People's Dictionary, University of Minnesota";

    private readonly HttpClientInterface $httpClient;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly string $baseUrl,
        int $timeout = 5,
        ?HttpClientInterface $httpClient = null,
        private readonly ?NorthCloudCache $cache = null,
        private readonly string $apiToken = '',
        bool $allowInsecure = false,
        ?LoggerInterface $logger = null,
    ) {
        self::assertValidBaseUrl($baseUrl, $allowInsecure);
        $this->httpClient = $httpClient ?? new StreamHttpClient(timeout: (float) $timeout);
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Validate a candidate base URL to prevent SSRF / misconfiguration.
     *
     * Allows https by default. http is rejected unless explicitly permitted
     * via the $allowInsecure flag (intended for local dev loopbacks only).
     */
    private static function assertValidBaseUrl(string $url, bool $allowInsecure): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme === 'https') {
            return;
        }

        if ($scheme === 'http' && $allowInsecure) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'NorthCloudClient requires an https base URL; got %s. Pass allowInsecure: true to permit http (local dev only).',
            $url !== '' ? $url : '(empty)',
        ));
    }

    /**
     * Fetch current leadership for a community.
     *
     * @return list<array{id: string, name: string, role: string, role_title?: string, email?: string, phone?: string, verified: bool}>|null
     */
    public function getPeople(string $ncId): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/communities/' . urlencode($ncId) . '/people?current_only=true';
        $json = $this->doRequest($url);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['people']) || !is_array($data['people'])) {
            $this->logger->warning(sprintf('NorthCloud people response malformed for community %s', $ncId));
            return null;
        }

        return $data['people'];
    }

    /**
     * Fetch band office contact info for a community.
     *
     * @return array{address_line1?: string, address_line2?: string, city?: string, province?: string, postal_code?: string, phone?: string, fax?: string, email?: string, toll_free?: string, office_hours?: string, verified: bool}|null
     */
    public function getBandOffice(string $ncId): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/communities/' . urlencode($ncId) . '/band-office';
        $json = $this->doRequest($url);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['band_office']) || !is_array($data['band_office'])) {
            return null;
        }

        return $data['band_office'];
    }

    /**
     * Fetch paginated dictionary entries from NorthCloud.
     *
     * @return array{entries: list<array<string, mixed>>, total: int, attribution: string}|null
     */
    public function getDictionaryEntries(int $page = 1, int $limit = 50): ?array
    {
        $offset = ($page - 1) * $limit;
        $url = rtrim($this->baseUrl, '/') . '/api/v1/dictionary/entries?limit=' . $limit . '&offset=' . $offset;
        $json = $this->doRequest($url);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            $this->logger->warning('NorthCloud dictionary entries response malformed');
            return null;
        }

        return [
            'entries' => $data['entries'],
            'total' => (int) ($data['total'] ?? 0),
            'attribution' => self::DICTIONARY_ATTRIBUTION,
        ];
    }

    /**
     * Search dictionary entries via NorthCloud full-text search.
     *
     * @return array{entries: list<array<string, mixed>>, total: int, attribution: string}|null
     */
    public function searchDictionary(string $query): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/dictionary/search?q=' . urlencode($query);
        $json = $this->doRequest($url);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
            $this->logger->warning(sprintf('NorthCloud dictionary search response malformed for query: %s', $query));
            return null;
        }

        return [
            'entries' => $data['entries'],
            'total' => (int) ($data['total'] ?? 0),
            'attribution' => self::DICTIONARY_ATTRIBUTION,
        ];
    }

    /**
     * Fetch recent content from NorthCloud Search API.
     *
     * @param int $limit Maximum results
     * @param string|null $since ISO date (YYYY-MM-DD) to fetch content from
     * @param string $searchQuery Optional text query (empty by default)
     * @param list<string> $topics Topic filters (default: ['indigenous'])
     * @param int $minQuality Minimum quality score (default: 60)
     * @return array{hits: list<mixed>, total_hits: int}|null
     *                                             Hits are filtered to a list; each element may be a full hit
     *                                             array or malformed data (callers should validate with is_array()).
     */
    public function getRecentContent(int $limit = 20, ?string $since = null, string $searchQuery = '', array $topics = ['indigenous'], int $minQuality = 60): ?array
    {
        $query = 'size=' . $limit . '&min_quality=' . $minQuality;
        if ($searchQuery !== '') {
            $query .= '&q=' . urlencode($searchQuery);
        }
        foreach ($topics as $topic) {
            $query .= '&topics[]=' . urlencode($topic);
        }
        if ($since !== null) {
            $query .= '&from_date=' . urlencode($since);
        }

        $url = rtrim($this->baseUrl, '/') . '/api/v1/search?' . $query;
        $json = $this->doRequest($url);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['hits']) || !is_array($data['hits'])) {
            $this->logger->warning('NorthCloud search response malformed');
            return null;
        }

        return [
            'hits' => $data['hits'],
            'total_hits' => (int) ($data['total_hits'] ?? 0),
        ];
    }

    /**
     * Execute a search query against the NorthCloud Search API.
     *
     * Scalar values are encoded directly. list<string> values are expanded to
     * `key[]=v1&key[]=v2` (Go's query parser requires explicit bracket notation).
     *
     * @param array<string, string|int|list<string>> $params Query parameters
     * @return array<string, mixed>|null
     */
    public function search(array $params): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/search?' . $this->buildQueryString($params);
        $json = $this->doRequest($url);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->logger->warning('NorthCloud search response malformed');
            return null;
        }

        return $data;
    }

    /**
     * Encode params for NorthCloud Search API.
     *
     * @param array<string, string|int|list<string>> $params
     */
    private function buildQueryString(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $parts[] = urlencode($key . '[]') . '=' . urlencode($item);
                }
                continue;
            }
            $parts[] = urlencode($key) . '=' . urlencode((string) $value);
        }

        return implode('&', $parts);
    }

    /**
     * Link community sources via NorthCloud API.
     *
     * Requires authentication (api_token).
     *
     * @return array<string, mixed>|null
     */
    public function linkSources(bool $dryRun = true): ?array
    {
        $dryRunParam = $dryRun ? 'true' : 'false';
        $url = rtrim($this->baseUrl, '/') . '/api/v1/communities/link-sources?dry_run=' . $dryRunParam;
        $json = $this->doAuthenticatedRequest($url, 'POST');

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->logger->warning('NorthCloud link-sources response malformed');
            return null;
        }

        return $data;
    }

    /**
     * Create a leadership scrape job for a community.
     *
     * Requires authentication (api_token).
     *
     * @return array<string, mixed>|null
     */
    public function createLeadershipScrapeJob(string $ncId): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/api/v1/crawl/jobs';
        $body = json_encode([
            'community_id' => $ncId,
            'job_type' => 'leadership_scrape',
        ], JSON_THROW_ON_ERROR);

        $json = $this->doAuthenticatedRequest($url, 'POST', $body);

        if ($json === null) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            $this->logger->warning(sprintf('NorthCloud create crawl job response malformed for community %s', $ncId));
            return null;
        }

        return $data;
    }

    private function doRequest(string $url): ?string
    {
        if ($this->cache !== null) {
            $cached = $this->cache->get($url);
            if ($cached !== null) {
                return $cached;
            }
        }

        try {
            $response = $this->httpClient->get($url);
        } catch (HttpRequestException $e) {
            $this->logger->warning(sprintf('NorthCloud API request failed: %s (%s)', $url, $e->getMessage()));
            return null;
        }

        if (!$response->isSuccess()) {
            $this->logger->warning(sprintf(
                'NorthCloud API request returned non-success status %d: %s',
                $response->statusCode,
                $url,
            ));
            return null;
        }

        if ($this->cache !== null) {
            $this->cache->set($url, $response->body);
        }

        return $response->body;
    }

    private function doAuthenticatedRequest(string $url, string $method = 'POST', ?string $body = null): ?string
    {
        if ($this->apiToken === '') {
            $this->logger->warning('NorthCloud API token not configured, cannot make authenticated request');
            return null;
        }

        $headers = [
            'Authorization' => 'Bearer ' . $this->apiToken,
            'Content-Type' => 'application/json',
        ];

        try {
            $response = $this->httpClient->request($method, $url, $headers, $body);
        } catch (HttpRequestException $e) {
            $this->logger->warning(sprintf('NorthCloud authenticated API request failed: %s (%s)', $url, $e->getMessage()));
            return null;
        }

        if (!$response->isSuccess()) {
            $this->logger->warning(sprintf(
                'NorthCloud authenticated API request returned non-success status %d: %s',
                $response->statusCode,
                $url,
            ));
            return null;
        }

        return $response->body;
    }
}
