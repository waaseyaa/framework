<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

/**
 * Time bounds for a single provider HTTP exchange.
 *
 * Three bounds, because a total timeout alone cannot bound a stalled peer
 * cheaply:
 * - `connectSeconds` caps the connection phase (DNS, TCP, TLS handshake) on its
 *   own, so a peer that accepts and never negotiates fails fast instead of
 *   spending the whole request budget.
 * - `totalSeconds` caps the exchange end to end.
 * - the low-speed pair tears the transfer down once it stops delivering bytes.
 *   For a stream this is the bound that matters: a caller's own deadline runs
 *   inside the chunk callback, and a stalled stream delivers no chunk to run it.
 *
 * The low-speed pair is off unless both halves are set. A non-streaming request
 * is legitimately silent while the model generates, so only the streaming
 * profile enables it by default.
 *
 * @api
 */
final readonly class ProviderTimeouts
{
    private const float DEFAULT_CONNECT_SECONDS = 5.0;
    private const float DEFAULT_REQUEST_SECONDS = 120.0;
    private const float DEFAULT_STREAM_SECONDS = 300.0;
    private const int DEFAULT_STREAM_LOW_SPEED_BYTES = 1;
    private const int DEFAULT_STREAM_LOW_SPEED_SECONDS = 30;

    /**
     * @param float $connectSeconds bound on the connection phase alone
     * @param float $totalSeconds bound on the whole exchange
     * @param int $lowSpeedBytesPerSecond floor below which the transfer counts as stalled (0 disables)
     * @param int $lowSpeedSeconds how long the transfer may stay below that floor (0 disables)
     */
    public function __construct(
        public float $connectSeconds = self::DEFAULT_CONNECT_SECONDS,
        public float $totalSeconds = self::DEFAULT_REQUEST_SECONDS,
        public int $lowSpeedBytesPerSecond = 0,
        public int $lowSpeedSeconds = 0,
    ) {
        if ($connectSeconds <= 0.0 || $totalSeconds <= 0.0) {
            throw new \InvalidArgumentException('Provider timeouts must be positive.');
        }

        if ($connectSeconds > $totalSeconds) {
            throw new \InvalidArgumentException(
                'A connect timeout longer than the total timeout can never be reached.',
            );
        }

        if ($lowSpeedBytesPerSecond < 0 || $lowSpeedSeconds < 0) {
            throw new \InvalidArgumentException('Low-speed limits cannot be negative.');
        }

        if (($lowSpeedBytesPerSecond > 0) !== ($lowSpeedSeconds > 0)) {
            throw new \InvalidArgumentException(
                'A low-speed abort needs both a byte floor and a window; set both or neither.',
            );
        }
    }

    /**
     * The default non-streaming profile: the response arrives in one piece once
     * the model has finished, so there is nothing to measure a byte rate against
     * and the low-speed abort stays off.
     */
    public static function forRequest(): self
    {
        return new self(
            connectSeconds: self::DEFAULT_CONNECT_SECONDS,
            totalSeconds: self::DEFAULT_REQUEST_SECONDS,
        );
    }

    /**
     * The default streaming profile. The total stays generous because a healthy
     * long generation is legitimate; the low-speed window is the real bound, and
     * it holds a silent peer to tens of seconds rather than the full total.
     */
    public static function forStreaming(): self
    {
        return new self(
            connectSeconds: self::DEFAULT_CONNECT_SECONDS,
            totalSeconds: self::DEFAULT_STREAM_SECONDS,
            lowSpeedBytesPerSecond: self::DEFAULT_STREAM_LOW_SPEED_BYTES,
            lowSpeedSeconds: self::DEFAULT_STREAM_LOW_SPEED_SECONDS,
        );
    }

    /**
     * @return array<int, int> cURL options, keyed by CURLOPT_* constant
     */
    public function curlOptions(): array
    {
        $options = [
            \CURLOPT_CONNECTTIMEOUT_MS => self::milliseconds($this->connectSeconds),
            \CURLOPT_TIMEOUT_MS => self::milliseconds($this->totalSeconds),
        ];

        if ($this->lowSpeedBytesPerSecond > 0) {
            $options[\CURLOPT_LOW_SPEED_LIMIT] = $this->lowSpeedBytesPerSecond;
            $options[\CURLOPT_LOW_SPEED_TIME] = $this->lowSpeedSeconds;
        }

        return $options;
    }

    private static function milliseconds(float $seconds): int
    {
        return \max(1, (int) \round($seconds * 1000));
    }
}
