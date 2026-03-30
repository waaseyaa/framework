<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\Field\Tests\Contract\FieldFormatterContractTest;
use Waaseyaa\SSR\Formatter\HtmlFormatter;

#[CoversNothing]
final class HtmlFormatterContractTest extends FieldFormatterContractTest
{
    protected function createFormatter(): FieldFormatterInterface
    {
        return new HtmlFormatter();
    }

    protected function getSampleValue(): mixed
    {
        return '<p>Hello <strong>world</strong></p>';
    }
}
