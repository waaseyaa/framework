<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\Field\Tests\Contract\FieldFormatterContractTest;
use Waaseyaa\SSR\Formatter\ImageFormatter;

#[CoversNothing]
final class ImageFormatterContractTest extends FieldFormatterContractTest
{
    protected function createFormatter(): FieldFormatterInterface
    {
        return new ImageFormatter();
    }

    protected function getSampleValue(): mixed
    {
        return '/images/photo.jpg';
    }
}
