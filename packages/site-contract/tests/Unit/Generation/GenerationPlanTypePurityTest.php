<?php

declare(strict_types=1);

namespace Waaseyaa\SiteContract\Tests\Unit\Generation;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ADR-025 D-6.1: an `ArtifactPlan` "contains no status, no diff, no filesystem
 * observation, and no reference to any project", and D-12.1's slice 2 ships
 * "pure values; nothing observes a project". These are the mechanical halves of
 * that claim, asserted against the source of the types this slice adds.
 */
#[CoversNothing]
final class GenerationPlanTypePurityTest extends TestCase
{
    private const string SOURCE_DIR = __DIR__ . '/../../../src/Generation/';

    /** @var list<string> */
    private const array SLICE_TYPES = [
        'ArtifactPlan.php',
        'ArtifactSetEvolution.php',
        'ComposerProviderRegistration.php',
        'GenerationUnitDisposition.php',
        'ObservedTargetMode.php',
        'ObservedTargetState.php',
        'ProjectStateIdentity.php',
        'ProjectStateTarget.php',
    ];

    #[Test]
    #[DataProvider('sliceTypeProvider')]
    public function itObservesNoProjectAndNoClock(string $file): void
    {
        $code = self::strippedCode($file);

        foreach ([
            'file_get_contents',
            'file_put_contents',
            'file_exists',
            'filemtime',
            'fileperms',
            'filesize',
            'is_file',
            'is_dir',
            'is_link',
            'is_readable',
            'is_writable',
            'is_executable',
            'realpath',
            'scandir',
            'glob',
            'opendir',
            'readdir',
            'readfile',
            'readlink',
            'symlink',
            'fopen',
            'unlink',
            'mkdir',
            'chmod',
            'chdir',
            'getcwd',
            'lstat',
            'tempnam',
            'sys_get_temp_dir',
            'SplFileInfo',
            'DirectoryIterator',
            'getenv',
            'date(',
            'time(',
            'DateTime',
            'microtime',
            'hrtime',
            'random_bytes',
            'uniqid',
            'proc_open',
            'shell_exec',
            'passthru',
            '$_SERVER',
            '$_ENV',
            '$_GET',
            '$_POST',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $code, "{$file} must not observe a project or a clock.");
        }
    }

    #[Test]
    #[DataProvider('sliceTypeProvider')]
    public function itImportsNothingOutsideItsOwnPackage(string $file): void
    {
        $code = self::strippedCode($file);

        preg_match_all('/^\s*use\s+([^;]+);/m', $code, $matches);
        foreach ($matches[1] as $import) {
            self::assertStringStartsWith(
                'Waaseyaa\\SiteContract\\',
                trim($import),
                "{$file} may import only site-contract types.",
            );
        }
        self::assertDoesNotMatchRegularExpression(
            '/\\\\Waaseyaa\\\\(?!SiteContract\\\\)/',
            $code,
            "{$file} may name only site-contract types.",
        );
    }

    #[Test]
    #[DataProvider('sliceTypeProvider')]
    public function itClaimsNoOwnershipDocumentAndNoReceiptSink(string $file): void
    {
        $code = self::strippedCode($file);

        self::assertStringNotContainsString('.waaseyaa/', $code, "{$file} must not name a project-relative authority document.");
        self::assertStringNotContainsString('receipt', strtolower($code), "{$file} must not carry a change-receipt member.");
    }

    #[Test]
    #[DataProvider('sliceTypeProvider')]
    public function itEmitsNoGenerationErrorCodeBeforeTheCodedFamilyExists(string $file): void
    {
        self::assertDoesNotMatchRegularExpression(
            '/GEN\d{3}/',
            self::strippedCode($file),
            "{$file} must not emit a GEN0xx code: the coded exception family is a later slice.",
        );
    }

    /** @return iterable<string, array{string}> */
    public static function sliceTypeProvider(): iterable
    {
        foreach (self::SLICE_TYPES as $file) {
            yield $file => [$file];
        }
    }

    private static function strippedCode(string $file): string
    {
        $source = file_get_contents(self::SOURCE_DIR . $file);
        self::assertIsString($source, "Unable to read {$file}.");

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
