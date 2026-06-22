<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Agent\Tests\Unit\Message;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\AI\Agent\Message\RunAgent;

/**
 * Construction + Messenger-envelope round-trip smoke test for {@see RunAgent}.
 *
 * The handler reloads the row from the repository, so the message must
 * remain a thin envelope around the run id — anything else risks
 * stale-data bugs between dispatch and pickup.
 */
#[CoversClass(RunAgent::class)]
final class RunAgentTest extends TestCase
{
    #[Test]
    public function carriesOnlyTheRunIdAndIsReadonly(): void
    {
        $id = Uuid::v4();
        $message = new RunAgent($id);

        self::assertSame($id, $message->runId);

        $reflection = new \ReflectionClass($message);
        self::assertTrue($reflection->isFinal());
        self::assertTrue($reflection->isReadOnly());
    }

    #[Test]
    public function survivesMessengerEnvelopeRoundTrip(): void
    {
        $id = Uuid::v4();
        $envelope = new Envelope(new RunAgent($id));

        $message = $envelope->getMessage();
        self::assertInstanceOf(RunAgent::class, $message);
        self::assertTrue($message->runId->equals($id));
    }
}
