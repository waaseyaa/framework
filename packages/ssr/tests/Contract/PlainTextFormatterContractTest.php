<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\Field\Tests\Contract\FieldFormatterContractTest;
use Waaseyaa\SSR\Formatter\PlainTextFormatter;

#[CoversNothing]
final class PlainTextFormatterContractTest extends FieldFormatterContractTest
{
    protected function createFormatter(): FieldFormatterInterface
    {
        return new PlainTextFormatter();
    }

    protected function getSampleValue(): mixed
    {
        return 'Hello world & "friends"';
    }
}
