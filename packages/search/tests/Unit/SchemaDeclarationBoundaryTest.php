<?php

declare(strict_types=1);

namespace Waaseyaa\Search\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * FW-SEARCH-PERSIST-01: only `Fts5SearchSchema` declares search schema. Every
 * other production file, and so every serving path, is DDL-free.
 */
#[CoversNothing]
final class SchemaDeclarationBoundaryTest extends TestCase
{
    private const string SCHEMA_OWNER = 'Fts5/Fts5SearchSchema.php';

    private const array FORBIDDEN = [
        'DDL' => '/\b(CREATE|ALTER|DROP)\s+((VIRTUAL\s+|TEMP(ORARY)?\s+)?TABLE|(UNIQUE\s+)?INDEX|TRIGGER|VIEW)\b/i',
        'schema mutation API' => '/->\s*(createTable|dropTable|addField|dropField|addIndex|dropIndex|addPrimaryKey|addUniqueKey|executeStatement)\s*\(/',
    ];

    #[Test]
    public function onlyTheSchemaOwnerDeclaresSchema(): void
    {
        $source = dirname(__DIR__, 2) . '/src';
        $violations = [];
        $owner = null;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($source) + 1));
            $code = $this->codeWithoutComments((string) file_get_contents($file->getPathname()));
            if ($relative === self::SCHEMA_OWNER) {
                $owner = $code;
                continue;
            }
            foreach (self::FORBIDDEN as $label => $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $violations[] = sprintf('%s: %s', $relative, $label);
                }
            }
        }

        self::assertSame([], $violations);
        self::assertNotNull($owner, 'the schema owner exists');
        self::assertMatchesRegularExpression(self::FORBIDDEN['DDL'], $owner, 'the pattern recognizes the owner\'s DDL');
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
