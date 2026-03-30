<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Provenance;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\CLI\Provenance\ComposerProvenanceReporter;

#[CoversClass(ComposerProvenanceReporter::class)]
final class ComposerProvenanceReporterTest extends TestCase
{
    #[Test]
    public function detects_multiple_constraint_patterns(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa_prov_test_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            file_put_contents($dir . '/composer.json', <<<'JSON'
{
    "require": {
        "waaseyaa/entity": "^0.1.0-alpha.37",
        "waaseyaa/foundation": "^0.1.0-alpha.63"
    }
}
JSON);
            file_put_contents($dir . '/composer.lock', <<<'JSON'
{
    "packages": [],
    "packages-dev": []
}
JSON);

            $report = (new ComposerProvenanceReporter($dir))->analyze();
            $this->assertGreaterThan(1, count($report->uniqueConstraints));
            $this->assertTrue($report->hasDrift());
            $this->assertNotEmpty($report->driftMessages);
        } finally {
            $this->deleteTree($dir);
        }
    }

    #[Test]
    public function single_constraint_pattern_no_drift_from_constraints(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa_prov_test_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            file_put_contents($dir . '/composer.json', <<<'JSON'
{
    "require": {
        "waaseyaa/entity": "^0.1",
        "waaseyaa/foundation": "^0.1"
    }
}
JSON);
            file_put_contents($dir . '/composer.lock', <<<'JSON'
{
    "packages": [],
    "packages-dev": []
}
JSON);

            $report = (new ComposerProvenanceReporter($dir))->analyze();
            $this->assertSame(1, count($report->uniqueConstraints));
            $constraintDrift = false;
            foreach ($report->driftMessages as $m) {
                if (str_contains($m, 'constraint')) {
                    $constraintDrift = true;
                }
            }
            $this->assertFalse($constraintDrift);
        } finally {
            $this->deleteTree($dir);
        }
    }

    #[Test]
    public function main_exits_failure_on_drift_unless_report_only(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa_prov_main_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            file_put_contents($dir . '/composer.json', <<<'JSON'
{
    "require": {
        "waaseyaa/entity": "^0.1.0-alpha.37",
        "waaseyaa/foundation": "^0.1.0-alpha.63"
    }
}
JSON);
            file_put_contents($dir . '/composer.lock', <<<'JSON'
{
    "packages": [],
    "packages-dev": []
}
JSON);

            $this->assertSame(1, ComposerProvenanceReporter::main($dir, []));
            $this->assertSame(1, ComposerProvenanceReporter::main($dir, ['--strict']));
            $this->assertSame(0, ComposerProvenanceReporter::main($dir, ['--report-only']));
        } finally {
            $this->deleteTree($dir);
        }
    }

    #[Test]
    public function main_exits_success_when_no_drift(): void
    {
        $dir = sys_get_temp_dir() . '/waaseyaa_prov_main_' . bin2hex(random_bytes(4));
        mkdir($dir, 0777, true);
        try {
            file_put_contents($dir . '/composer.json', <<<'JSON'
{
    "require": {
        "waaseyaa/entity": "^0.1",
        "waaseyaa/foundation": "^0.1"
    }
}
JSON);
            file_put_contents($dir . '/composer.lock', <<<'JSON'
{
    "packages": [],
    "packages-dev": []
}
JSON);

            $this->assertSame(0, ComposerProvenanceReporter::main($dir, []));
            $this->assertSame(0, ComposerProvenanceReporter::main($dir, ['--strict']));
        } finally {
            $this->deleteTree($dir);
        }
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($path);
    }
}
