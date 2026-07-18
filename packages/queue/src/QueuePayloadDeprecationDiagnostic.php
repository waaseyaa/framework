<?php

declare(strict_types=1);

namespace Waaseyaa\Queue;

/** Bounded dormant detector for entity objects embedded in queue messages. @api */
final class QueuePayloadDeprecationDiagnostic
{
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
            throw new \Waaseyaa\Queue\Exception\InvalidPersistentPayload(
                sprintf('Queue message %s contains an entity; dispatch identifiers or an explicit public projection.', $message::class),
            );
        }
        if ($type !== null && !isset($this->emitted[$type])) {
            $this->emitted[$type] = true;
            ($this->emit)('entity.deprecation', [
                'boundary' => 'queue.dispatch',
                'reason' => 'serialized_entity_payload',
                'value_type' => $type,
            ]);
        }

        return $message;
    }

    /** @param \WeakMap<object, true> $seen */
    private function entityType(mixed $value, int $depth, int &$remaining, \WeakMap $seen): ?string
    {
        if ($depth > 16 || --$remaining < 0) {
            return null;
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
        if (!is_object($value) || isset($seen[$value])) {
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
                continue;
            }
            $found = $this->entityType($child, $depth + 1, $remaining, $seen);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
