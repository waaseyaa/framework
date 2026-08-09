<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\ReleaseTooling;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SplitMainWorkflowShapeTest extends TestCase
{
    private string $workflow = '';

    protected function setUp(): void
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/split-main.yml');
        self::assertNotFalse($contents);
        $this->workflow = $contents;
    }

    #[Test]
    public function workflow_is_manual_exact_main_and_green_ci_only(): void
    {
        self::assertStringContainsString('workflow_dispatch:', $this->workflow);
        self::assertStringContainsString('requested}" != "${current_main}', $this->workflow);
        self::assertStringContainsString('wait-for-green-ci', $this->workflow);
        self::assertStringContainsString('resolve-split-main-targets', $this->workflow);
        self::assertStringContainsString('status=in_progress', $this->workflow);
    }

    #[Test]
    public function workflow_updates_only_main_with_a_lease_and_records_provenance(): void
    {
        self::assertStringContainsString('--force-with-lease=', $this->workflow);
        self::assertStringContainsString('${split_sha}:refs/heads/main', $this->workflow);
        self::assertStringContainsString('split-main-provenance-', $this->workflow);
        self::assertStringContainsString('release:false', $this->workflow);
    }

    #[Test]
    public function workflow_has_no_release_publication_surface(): void
    {
        self::assertStringNotContainsString('refs/tags/', $this->workflow);
        self::assertStringNotContainsString('PACKAGIST_', $this->workflow);
        self::assertStringNotContainsString('update-package', $this->workflow);
        self::assertStringNotContainsString('create-release', $this->workflow);
    }
}
