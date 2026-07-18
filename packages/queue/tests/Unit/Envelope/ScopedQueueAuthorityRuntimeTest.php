<?php

declare(strict_types=1);

namespace Waaseyaa\Queue\Tests\Unit\Envelope;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Queue\Envelope\QueueAuthorityScopeInterface;
use Waaseyaa\Queue\Envelope\QueueEnvelopeV1;
use Waaseyaa\Queue\Envelope\QueueSystemReason;
use Waaseyaa\Queue\Envelope\ScopedQueueAuthorityRuntime;

final class ScopedQueueAuthorityRuntimeTest extends TestCase
{
    #[Test]
    public function authorityIsResolvedFreshForEveryHandlerInvocation(): void
    {
        $resolved = 0;
        $closed = 0;
        $runtime = new ScopedQueueAuthorityRuntime(static function (QueueEnvelopeV1 $envelope) use (&$resolved, &$closed): QueueAuthorityScopeInterface {
            $resolved++;

            return new class ($closed) implements QueueAuthorityScopeInterface {
                public function __construct(private int &$closed) {}
                public function close(): void
                {
                    $this->closed++;
                }
            };
        });
        $first = QueueEnvelopeV1::forSystem(serialize(new \stdClass()), QueueSystemReason::SystemJob, 'fixture', null, null, 'job-1');
        $second = QueueEnvelopeV1::forSystem(serialize(new \stdClass()), QueueSystemReason::SystemJob, 'fixture', null, null, 'job-2');

        $runtime->run($first, static function () use (&$resolved, &$closed): void {
            self::assertSame(1, $resolved);
            self::assertSame(0, $closed);
        });
        $runtime->run($second, static function () use (&$resolved, &$closed): void {
            self::assertSame(2, $resolved);
            self::assertSame(1, $closed);
        });

        self::assertSame(2, $resolved);
        self::assertSame(2, $closed);
    }

    #[Test]
    public function authorityIsClosedWhenTheHandlerThrows(): void
    {
        $active = false;
        $runtime = new ScopedQueueAuthorityRuntime(static function (QueueEnvelopeV1 $envelope) use (&$active): QueueAuthorityScopeInterface {
            $active = true;

            return new class ($active) implements QueueAuthorityScopeInterface {
                public function __construct(private bool &$active) {}
                public function close(): void
                {
                    $this->active = false;
                }
            };
        });
        $envelope = QueueEnvelopeV1::forSystem(serialize(new \stdClass()), QueueSystemReason::SystemJob, 'fixture', null, null, 'job-1');

        try {
            $runtime->run($envelope, static function () use (&$active): never {
                self::assertTrue($active);
                throw new \RuntimeException('fixture');
            });
            self::fail('Expected the handler exception.');
        } catch (\RuntimeException) {
            self::assertFalse($active);
        }
    }
}
