<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class CiPullRequestCoverageTest extends TestCase
{
    #[Test]
    #[DataProvider('requiredPullRequestWorkflows')]
    public function required_validation_runs_for_stacked_pull_request_bases(string $workflow): void
    {
        $contents = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/' . $workflow);

        self::assertStringContainsString('pull_request:', $contents);
        self::assertDoesNotMatchRegularExpression('/pull_request:\h*\R\h+branches:/', $contents);
    }

    /** @return iterable<string, array{string}> */
    public static function requiredPullRequestWorkflows(): iterable
    {
        yield 'framework CI' => ['ci.yml'];
        yield 'Admin SPA' => ['admin.yml'];
        yield 'changelog discipline' => ['changelog-discipline.yml'];
        yield 'surface parity' => ['surface-parity.yml'];
    }
}
