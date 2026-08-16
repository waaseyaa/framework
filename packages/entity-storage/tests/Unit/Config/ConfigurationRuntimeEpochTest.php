<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Activation\ConfigurationActivatorInterface;
use Waaseyaa\Config\Authority\ConfigurationActiveToken;
use Waaseyaa\EntityStorage\Config\ConfigurationRuntimeEpoch;

final class ConfigurationRuntimeEpochTest extends TestCase
{
    #[Test]
    public function exactObservedTokenRemainsCurrent(): void
    {
        $token = new ConfigurationActiveToken(str_repeat('a', 64), 7);
        $activator = $this->createStub(ConfigurationActivatorInterface::class);
        $activator->method('currentToken')->willReturn($token);
        $epoch = new ConfigurationRuntimeEpoch($activator, $token);

        self::assertFalse($epoch->hasChanged());
        self::assertStringStartsWith('configuration:', $epoch->fingerprint());
        self::assertStringNotContainsString($token->generationId, $epoch->fingerprint());
    }

    #[Test]
    public function sequenceGenerationOrMissingHeadChangeTheEpoch(): void
    {
        $observed = new ConfigurationActiveToken(str_repeat('a', 64), 7);
        foreach ([
            new ConfigurationActiveToken(str_repeat('a', 64), 8),
            new ConfigurationActiveToken(str_repeat('b', 64), 7),
            null,
        ] as $current) {
            $activator = $this->createStub(ConfigurationActivatorInterface::class);
            $activator->method('currentToken')->willReturn($current);

            self::assertTrue((new ConfigurationRuntimeEpoch($activator, $observed))->hasChanged());
        }
    }
}
