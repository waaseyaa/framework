<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class S1SchemaAuthorityContractTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    #[Test]
    public function every_executable_schema_boundary_has_one_reviewed_authority(): void
    {
        self::assertFileExists($this->root . '/packages/foundation/src/Migration/SchemaMutationCoordinator.php');
        self::assertFileExists($this->root . '/support/s1-schema-authority-roster.json');
        self::assertFileExists($this->root . '/bin/check-s1-schema-authority');
        self::assertTrue(is_executable($this->root . '/bin/check-s1-schema-authority'));

        exec(
            escapeshellarg(PHP_BINARY) . ' '
            . escapeshellarg($this->root . '/bin/check-s1-schema-authority') . ' 2>&1',
            $output,
            $exitCode,
        );

        self::assertSame(0, $exitCode, implode("\n", $output));
    }

    #[Test]
    public function checker_rejects_roster_omission_and_valid_class_substitution(): void
    {
        $canonical = json_decode(
            (string) file_get_contents($this->root . '/support/s1-schema-authority-roster.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $mutations = [
            'omitted occurrence' => static function (array &$roster): void {
                array_pop($roster['candidates']);
            },
            'valid class substitution' => static function (array &$roster): void {
                $roster['candidates'][0]['class'] = $roster['candidates'][0]['class'] === 'test-only'
                    ? 'explicit-exclusion'
                    : 'test-only';
            },
            'weakened query' => static function (array &$roster): void {
                unset($roster['patterns']['ddl_sql']);
            },
        ];

        foreach ($mutations as $name => $mutate) {
            $roster = $canonical;
            $mutate($roster);
            $path = tempnam(sys_get_temp_dir(), 's1-schema-roster-');
            self::assertNotFalse($path);
            try {
                file_put_contents($path, json_encode($roster, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $output = [];
                exec(
                    escapeshellarg(PHP_BINARY) . ' '
                    . escapeshellarg($this->root . '/bin/check-s1-schema-authority') . ' '
                    . escapeshellarg('--roster=' . $path) . ' 2>&1',
                    $output,
                    $exitCode,
                );
                self::assertNotSame(0, $exitCode, "{$name}: " . implode("\n", $output));
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    #[Test]
    public function production_classifier_does_not_auto_approve_new_paths(): void
    {
        $checker = (string) file_get_contents($this->root . '/bin/check-s1-schema-authority');

        self::assertStringContainsString('return $reviewed[$path] ?? \'unclassified\';', $checker);
        self::assertStringNotContainsString("str_starts_with(\$path, 'packages/", $checker);
    }

    #[Test]
    public function exact_installed_artifact_reproduces_the_schema_authority_contract_offline(): void
    {
        $script = $this->root . '/tests/PackagedForm/check-s1-schema-authority-artifact';
        self::assertFileExists($script);
        self::assertTrue(is_executable($script));

        exec(escapeshellarg($script) . ' 2>&1', $output, $exitCode);

        self::assertSame(0, $exitCode, implode("\n", $output));
        self::assertStringContainsString('S1 installed-artifact schema authority contract passed.', implode("\n", $output));
    }
}
