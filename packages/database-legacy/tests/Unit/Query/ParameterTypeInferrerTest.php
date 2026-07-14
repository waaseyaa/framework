<?php

declare(strict_types=1);

namespace Waaseyaa\Database\Tests\Unit\Query;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Database\Query\ParameterTypeInferrer;

#[CoversClass(ParameterTypeInferrer::class)]
final class ParameterTypeInferrerTest extends TestCase
{
    #[Test]
    public function integerArraysUseIntegerBinding(): void
    {
        self::assertSame(ArrayParameterType::INTEGER, ParameterTypeInferrer::array([1, 2]));
        self::assertSame(ArrayParameterType::INTEGER, ParameterTypeInferrer::array([true, false]));
        self::assertSame(ArrayParameterType::STRING, ParameterTypeInferrer::array(['1', '2']));
        self::assertSame(ArrayParameterType::STRING, ParameterTypeInferrer::array([]));
    }

    #[Test]
    public function floatsHaveAnExplicitDbalBindingPolicy(): void
    {
        self::assertSame(ParameterType::STRING, ParameterTypeInferrer::scalar(1.25));
    }
}
