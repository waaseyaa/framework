<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Security;

/**
 * Non-exporting credential/key handle backed by either a rotating reference or
 * guarded process-local bytes accepted at a marked legacy ingress.
 *
 * @api
 */
final class SecretHandle implements \JsonSerializable
{
    /** @var \WeakMap<object, mixed>|null */
    private static ?\WeakMap $custody = null;

    /** @var array<class-string<SecretConsumerInterface<mixed>>, true> */
    private array $allowedConsumers;

    /**
     * @param list<class-string<SecretConsumerInterface<mixed>>> $allowedConsumers
     */
    private function __construct(
        ?SecretResolverRegistry $registry,
        ?SecretReference $reference,
        ?SensitiveValue $value,
        ?object $valueAuthority,
        private readonly string $package,
        private readonly SecretClass $secretClass,
        private readonly string $purpose,
        private readonly string $fingerprint,
        array $allowedConsumers,
    ) {
        $this->allowedConsumers = array_fill_keys($allowedConsumers, true);
        if (self::$custody === null) {
            self::$custody = new \WeakMap();
        }
        self::$custody[$this] = [
            'registry' => $registry,
            'reference' => $reference,
            'value' => $value,
            'authority' => $valueAuthority,
        ];
    }

    /**
     * @param list<class-string<SecretConsumerInterface<mixed>>> $allowedConsumers
     */
    public static function fromReference(
        SecretResolverRegistry $registry,
        SecretReference $reference,
        string $package,
        array $allowedConsumers,
    ): self {
        self::assertPackage($package);
        self::assertConsumers($allowedConsumers, $reference->secretClass(), $reference->purpose());

        return new self(
            $registry,
            $reference,
            null,
            null,
            $package,
            $reference->secretClass(),
            $reference->purpose(),
            $reference->fingerprint(),
            $allowedConsumers,
        );
    }

    /**
     * @param list<class-string<SecretConsumerInterface<mixed>>> $allowedConsumers
     */
    public static function fromBytes(
        #[\SensitiveParameter]
        string $bytes,
        SecretClass $secretClass,
        string $purpose,
        string $version,
        array $allowedConsumers,
    ): self {
        self::assertConsumers($allowedConsumers, $secretClass, $purpose);
        $authority = new \stdClass();
        $value = SensitiveValue::fromBytes($bytes, $secretClass, $version);
        $value->bindConsumerAuthority($authority);
        $fingerprint = hash('sha256', json_encode([
            'class' => $secretClass->value,
            'purpose' => $purpose,
            'version' => $version,
            'source' => 'process-local',
        ], JSON_THROW_ON_ERROR));

        return new self(
            null,
            null,
            $value,
            $authority,
            'process-local/guarded',
            $secretClass,
            $purpose,
            $fingerprint,
            $allowedConsumers,
        );
    }

    /**
     * @template T
     * @param SecretConsumerInterface<T> $consumer
     * @return T
     */
    public function consume(SecretConsumerInterface $consumer): mixed
    {
        $consumerClass = $consumer::class;
        if (!isset($this->allowedConsumers[$consumerClass])
            || $consumerClass::secretClass() !== $this->secretClass
            || $consumerClass::purpose() !== $this->purpose) {
            throw new SecretConsumptionException(SecretConsumptionCode::ConsumerMismatch, $this->fingerprint);
        }

        /**
         * @var array{
         *     registry: SecretResolverRegistry|null,
         *     reference: SecretReference|null,
         *     value: SensitiveValue|null,
         *     authority: object|null,
         * }|null $custody
         */
        $custody = self::$custody[$this] ?? null;
        if (!is_array($custody)) {
            throw new SecretConsumptionException(SecretConsumptionCode::ConsumerFailure, $this->fingerprint);
        }
        if ($custody['registry'] !== null && $custody['reference'] !== null) {
            return $custody['registry']->consume($custody['reference'], $this->package, $consumer);
        }
        if ($custody['value'] === null || $custody['authority'] === null) {
            throw new SecretConsumptionException(SecretConsumptionCode::ConsumerFailure, $this->fingerprint);
        }

        try {
            return $custody['value']->consumeWith($consumer, $custody['authority']);
        } catch (\Throwable) {
            throw new SecretConsumptionException(SecretConsumptionCode::ConsumerFailure, $this->fingerprint);
        }
    }

    /** @return array{secret_handle: string, class: string, purpose: string} */
    public function __debugInfo(): array
    {
        return $this->redactedView();
    }

    /** @return array{secret_handle: string, class: string, purpose: string} */
    public function jsonSerialize(): array
    {
        return $this->redactedView();
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('Secret handles cannot be serialized.');
    }

    private function __clone() {}

    /**
     * @param list<class-string> $consumerClasses
     */
    private static function assertConsumers(array $consumerClasses, SecretClass $secretClass, string $purpose): void
    {
        if ($consumerClasses === []) {
            throw new \InvalidArgumentException('Secret handles require at least one purpose consumer.');
        }
        foreach ($consumerClasses as $consumerClass) {
            if (!is_a($consumerClass, SecretConsumerInterface::class, true)
                || $consumerClass::secretClass() !== $secretClass
                || $consumerClass::purpose() !== $purpose) {
                throw new \InvalidArgumentException('Secret-handle consumers must match the guarded class and purpose.');
            }
        }
    }

    private static function assertPackage(string $package): void
    {
        if (!preg_match('#^[a-z0-9][a-z0-9._-]*/[a-z0-9][a-z0-9._-]*$#D', $package)) {
            throw new \InvalidArgumentException('Secret-handle packages must use vendor/package identifiers.');
        }
    }

    /** @return array{secret_handle: string, class: string, purpose: string} */
    private function redactedView(): array
    {
        return [
            'secret_handle' => $this->fingerprint,
            'class' => $this->secretClass->value,
            'purpose' => $this->purpose,
        ];
    }
}
