<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FW-AIV-PERSIST-01: ai-vector's production code reaches the database only
 * through the framework layer and never declares schema. The `embeddings`
 * table belongs to the package migration.
 */
#[CoversNothing]
final class PersistenceBoundaryTest extends TestCase
{
    private const array FORBIDDEN = [
        'raw PDO' => '/\\\\?\bPDO\b/',
        'native connection' => '/getNativeConnection\s*\(/',
        'DDL' => '/\b(CREATE|ALTER|DROP)\s+(TABLE|INDEX)\b/i',
        'SQLite-only upsert' => '/INSERT\s+OR\s+REPLACE/i',
        'schema mutation API' => '/->\s*(createTable|dropTable|addField|dropField|addIndex|dropIndex|addPrimaryKey|addUniqueKey)\s*\(/',
    ];

    #[Test]
    public function productionSourceHasNoRawDatabaseAccessOrSchemaDeclarations(): void
    {
        $source = dirname(__DIR__, 2) . '/src';
        $violations = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $code = $this->codeWithoutComments((string) file_get_contents($file->getPathname()));
            foreach (self::FORBIDDEN as $label => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = sprintf('%s: %s', substr($file->getPathname(), strlen($source) + 1), $label);
                }
            }
        }

        self::assertSame([], $violations);
    }

    private function codeWithoutComments(string $code): string
    {
        $kept = '';
        foreach (\PhpToken::tokenize($code) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                $kept .= $token->text;
            }
        }

        return $kept;
    }
}
