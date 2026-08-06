<?php

declare(strict_types=1);

namespace Waaseyaa\Testing\Traits;

/**
 * Trait providing event interaction helpers for tests.
 *
 * Allows tests to set up expected events and to "fake" events
 * by capturing dispatched events without executing real listeners.
 *
 * @api
 * @deprecated since 0.1.0-alpha.288. Attach listeners to the real Symfony
 *   event dispatcher used by the system under test.
 */
trait InteractsWithEvents
{
    /**
     * Events that are expected to be dispatched during the test.
     *
     * @var string[]
     */
    private array $expectedEvents = [];

    /**
     * Events that have been recorded as dispatched.
     *
     * @var array<string, array<int, mixed>>
     */
    private array $dispatchedEvents = [];

    /**
     * Whether event faking is active.
     */
    private bool $eventsFaked = false;

    /**
     * Declare events that are expected to be dispatched during the test.
     *
     * @param string[] $events List of event class names or event names.
     * @return static
     */
    protected function expectsEvents(array $events): static
    {
        $this->expectedEvents = array_merge($this->expectedEvents, $events);

        return $this;
    }

    /**
     * Activate event faking. Dispatched events will be recorded
     * instead of being handled by real listeners.
     *
     * @param string[] $eventsToFake Specific events to fake. If empty, all events are faked.
     * @return static
     */
    protected function fakeEvents(array $eventsToFake = []): static
    {
        $this->eventsFaked = true;

        if ($eventsToFake !== []) {
            $this->expectedEvents = array_merge($this->expectedEvents, $eventsToFake);
        }

        return $this;
    }

    /**
     * Record that an event has been dispatched.
     *
     * Call this from your event dispatcher stub/mock to record
     * dispatched events for later assertion.
     *
     * @param string $eventName The event class name or identifier.
     * @param mixed $payload Optional event payload.
     */
    protected function recordEvent(string $eventName, mixed $payload = null): void
    {
        $this->dispatchedEvents[$eventName][] = $payload;
    }

    /**
     * Assert that a specific event was dispatched.
     *
     * @param string $eventName The event class name or identifier.
     * @param int|null $times Expected number of times, or null for "at least once".
     */
    protected function assertEventDispatched(string $eventName, ?int $times = null, string $message = ''): void
    {
        $count = count($this->dispatchedEvents[$eventName] ?? []);

        if ($times === null) {
            $this->assertGreaterThan(
                0,
                $count,
                $message ?: sprintf('Expected event "%s" to be dispatched at least once, but it was not.', $eventName),
            );
        } else {
            $this->assertSame(
                $times,
                $count,
                $message ?: sprintf('Expected event "%s" to be dispatched %d time(s), but it was dispatched %d time(s).', $eventName, $times, $count),
            );
        }
    }

    /**
     * Assert that a specific event was NOT dispatched.
     *
     * @param string $eventName The event class name or identifier.
     */
    protected function assertEventNotDispatched(string $eventName, string $message = ''): void
    {
        $count = count($this->dispatchedEvents[$eventName] ?? []);

        $this->assertSame(
            0,
            $count,
            $message ?: sprintf('Expected event "%s" not to be dispatched, but it was dispatched %d time(s).', $eventName, $count),
        );
    }

    /**
     * Assert that all expected events were dispatched.
     */
    protected function assertExpectedEventsDispatched(string $message = ''): void
    {
        foreach ($this->expectedEvents as $event) {
            $this->assertEventDispatched($event, message: $message);
        }
    }

    /**
     * Get all dispatched events.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function getDispatchedEvents(): array
    {
        return $this->dispatchedEvents;
    }

    /**
     * Check whether event faking is active.
     */
    protected function isEventsFaked(): bool
    {
        return $this->eventsFaked;
    }

    /**
     * Reset event tracking state.
     */
    private function resetEvents(): void
    {
        $this->expectedEvents = [];
        $this->dispatchedEvents = [];
        $this->eventsFaked = false;
    }
}
