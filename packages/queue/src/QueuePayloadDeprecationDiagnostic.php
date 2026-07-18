<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

/** Bounded dormant detector for entity objects embedded in queue messages. @api */
final class QueuePayloadDeprecationDiagnostic
{
    private const string UNINSPECTABLE_PAYLOAD = 'uninspectable-payload';

    /** @var \Closure(string, array<string, mixed>): void */
    private readonly \Closure $emit;
    /** @var array<string, true> */
    private array $emitted = [];
    private readonly PersistentQueueBoundaryConfig $config;

    /** @param callable(string, array<string, mixed>): void $emit */
    public function __construct(
        callable $emit,
        ?PersistentQueueBoundaryConfig $config = null,
    ) {
        $this->emit = \Closure::fromCallable($emit);
        $this->config = $config ?? PersistentQueueBoundaryConfig::dormant();
    }

    public function inspect(object $message): object
    {
        $seen = new \WeakMap();
        $remaining = 1_000;
        $type = $this->entityType($message, 0, $remaining, $seen);
        if ($type !== null && $this->config->rejectEntityPayloads) {
            $reason = $type === self::UNINSPECTABLE_PAYLOAD
                ? 'exceeds the bounded payload inspection contract'
                : 'contains an entity';
            throw new \Waaseyaa\Queue\Exception\InvalidPersistentPayload(
                sprintf('Queue message %s %s; dispatch identifiers or an explicit public projection.', $message::class, $reason),
            );
        }
        if ($type !== null && !isset($this->emitted[$type])) {
            $this->emitted[$type] = true;
            ($this->emit)('entity.deprecation', [
                'boundary' => 'queue.dispatch',
                'reason' => $type === self::UNINSPECTABLE_PAYLOAD ? 'payload_inspection_limit' : 'serialized_entity_payload',
                'value_type' => $type === self::UNINSPECTABLE_PAYLOAD ? $message::class : $type,
            ]);
        }

        return $message;
    }

    /** @param \WeakMap<object, true> $seen */
    private function entityType(mixed $value, int $depth, int &$remaining, \WeakMap $seen): ?string
    {
        if (!is_array($value) && !is_object($value)) {
            return null;
        }
        if ($depth > 16 || --$remaining < 0) {
            return self::UNINSPECTABLE_PAYLOAD;
        }
        if (is_array($value)) {
            foreach ($value as $child) {
                $found = $this->entityType($child, $depth + 1, $remaining, $seen);
                if ($found !== null) {
                    return $found;
                }
            }

            return null;
        }
        if (isset($seen[$value])) {
            return null;
        }
        $seen[$value] = true;
        $entityInterface = implode('\\', ['Waaseyaa', 'Entity', 'EntityInterface']);
        if ($value instanceof $entityInterface) {
            return $value::class;
        }
        $reflection = new \ReflectionObject($value);
        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic() || !$property->isInitialized($value)) {
                continue;
            }
            try {
                $child = $property->getValue($value);
            } catch (\Throwable) {
                return self::UNINSPECTABLE_PAYLOAD;
            }
            $found = $this->entityType($child, $depth + 1, $remaining, $seen);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
