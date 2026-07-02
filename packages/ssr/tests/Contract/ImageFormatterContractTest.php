<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Contract;

use PHPUnit\Framework\Attributes\CoversNothing;
use Waaseyaa\Field\FieldFormatterInterface;
use Waaseyaa\Field\Tests\Contract\AbstractFieldFormatterContract;
use Waaseyaa\SSR\Formatter\ImageFormatter;

#[CoversNothing]
final class ImageFormatterContractTest extends AbstractFieldFormatterContract
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
