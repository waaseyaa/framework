<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\Field\Tests\Contract\FieldFormatterContractTest;
use Waaseyaa\SSR\Formatter\BooleanFormatter;

#[CoversNothing]
final class BooleanFormatterContractTest extends FieldFormatterContractTest
{
    protected function createFormatter(): FieldFormatterInterface
    {
        return new BooleanFormatter();
    }

    protected function getSampleValue(): mixed
    {
        return true;
    }
}
