<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Field\FieldFormatterInterface;

#[CoversNothing]
abstract class FieldFormatterContractTest extends TestCase
{
    abstract protected function createFormatter(): FieldFormatterInterface;

    /**
     * Return a valid value that this formatter can handle.
     */
    abstract protected function getSampleValue(): mixed;

    #[Test]
    public function formatReturnsAString(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->format($this->getSampleValue());

        self::assertIsString($result);
    }

    #[Test]
    public function formatWithEmptySettingsReturnsAString(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->format($this->getSampleValue(), []);

        self::assertIsString($result);
    }

    #[Test]
    public function formatWithSampleValueDoesNotThrow(): void
    {
        $formatter = $this->createFormatter();

        // If we reach the assertion, no exception was thrown.
        $formatter->format($this->getSampleValue());

        self::assertTrue(true);
    }

    #[Test]
    public function formatNullReturnsAString(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->format(null);

        self::assertIsString($result);
    }

    #[Test]
    public function formatEmptyStringReturnsAString(): void
    {
        $formatter = $this->createFormatter();
        $result = $formatter->format('');

        self::assertIsString($result);
    }
}
