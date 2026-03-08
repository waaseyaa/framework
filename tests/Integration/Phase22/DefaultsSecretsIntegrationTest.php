<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase22;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

#[CoversNothing]
final class DefaultsSecretsIntegrationTest extends TestCase
{
    private string $defaultsDir;

    /** @var list<string> */
    private array $patterns = [
        '/sk-[A-Za-z0-9]{20,}/',
        '/ghp_[A-Za-z0-9]{20,}/',
        '/xox[bp]-[A-Za-z0-9\-]+/',
        '/ya29\.[A-Za-z0-9\-_]+/',
        '/AIza[A-Za-z0-9\-_]{35}/',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '%(mysql|postgres|postgresql|mongodb|redis)://[^@\s]+:[^@\s]+@%',
    ];

    protected function setUp(): void
    {
        $this->defaultsDir = dirname(__DIR__, 3) . '/defaults';
    }

    #[Test]
    public function yamlFilesContainNoSecrets(): void
    {
        $violations = [];

        foreach ($this->findFiles('yaml') as $file) {
            $data = Yaml::parseFile($file);

            foreach ($this->walkValues($data) as $value) {
                foreach ($this->patterns as $pattern) {
                    if (preg_match($pattern, (string) $value)) {
                        $violations[] = sprintf(
                            '%s: value "%s" matched pattern %s',
                            basename($file),
                            substr((string) $value, 0, 60),
                            $pattern,
                        );
                    }
                }
            }
        }

        $this->assertEmpty($violations, "Secret-like values found:\n" . implode("\n", $violations));
    }

    #[Test]
    public function jsonFilesContainNoSecrets(): void
    {
        $violations = [];

        foreach ($this->findFiles('json') as $file) {
            $data = json_decode(
                (string) file_get_contents($file),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            foreach ($this->walkValues($data) as $value) {
                foreach ($this->patterns as $pattern) {
                    if (preg_match($pattern, (string) $value)) {
                        $violations[] = sprintf(
                            '%s: value "%s" matched pattern %s',
                            basename($file),
                            substr((string) $value, 0, 60),
                            $pattern,
                        );
                    }
                }
            }
        }

        $this->assertEmpty($violations, "Secret-like values found:\n" . implode("\n", $violations));
    }

    /** @return list<string> */
    private function findFiles(string $extension): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->defaultsDir),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === $extension) {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** @return iterable<int, scalar> */
    private function walkValues(mixed $node): iterable
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                yield from $this->walkValues($child);
            }
        } elseif (is_string($node) || is_int($node) || is_float($node)) {
            yield $node;
        }
    }
}
