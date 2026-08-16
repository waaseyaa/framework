<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Provider;

/**
 * Transfers only provider retry taxonomy and bounded non-secret metadata out of
 * a guarded credential operation. Original exception text and chains are never
 * retained.
 *
 * @template-covariant T
 * @internal
 */
final class ProviderCredentialOutcome
{
    private const string RATE_LIMIT = 'rate-limit';
    private const string TRANSPORT = 'transport';
    private const string CLIENT = 'client';

    /** @param T|null $value */
    private function __construct(
        private readonly mixed $value,
        private readonly ?string $failure,
        private readonly int $retryAfterSeconds = 0,
    ) {}

    /**
     * @template R
     * @param \Closure(): R $operation
     * @return self<R>
     */
    public static function capture(\Closure $operation): self
    {
        try {
            return new self($operation(), null);
        } catch (RateLimitException $exception) {
            return new self(null, self::RATE_LIMIT, $exception->retryAfterSeconds);
        } catch (TransportException) {
            return new self(null, self::TRANSPORT);
        } catch (ClientErrorException) {
            return new self(null, self::CLIENT);
        }
    }

    /** @return T */
    public function unwrap(): mixed
    {
        return match ($this->failure) {
            self::RATE_LIMIT => throw new RateLimitException($this->retryAfterSeconds, 'Provider rate limited.'),
            self::TRANSPORT => throw new TransportException('Provider transport unavailable.'),
            self::CLIENT => throw new ClientErrorException('Provider request refused.'),
            null => $this->value,
            default => throw new \LogicException('Unknown provider credential outcome.'),
        };
    }
}
