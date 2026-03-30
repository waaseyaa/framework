<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\Field\Tests\Contract\FieldFormatterContractTest;
use Waaseyaa\SSR\Formatter\DateFormatter;

#[CoversNothing]
final class DateFormatterContractTest extends FieldFormatterContractTest
{
    protected function createFormatter(): FieldFormatterInterface
    {
        return new DateFormatter();
    }

    protected function getSampleValue(): mixed
    {
        return '2025-01-15 10:30:00';
    }
}
