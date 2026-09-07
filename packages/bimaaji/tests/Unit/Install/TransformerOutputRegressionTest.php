<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Install\Client\CopilotClientTransformer;
use Waaseyaa\Bimaaji\Install\Client\CursorClientTransformer;
use Waaseyaa\Bimaaji\Install\Client\GeminiClientTransformer;
use Waaseyaa\Bimaaji\Install\Client\JunieClientTransformer;
use Waaseyaa\Bimaaji\Install\Client\WindsurfClientTransformer;
use Waaseyaa\Bimaaji\Install\ClientTransformerInterface;
use Waaseyaa\Bimaaji\Tests\Fixture\InstallSkillFixtures;

/**
 * Proves the #2660 Part A refactor — every **single-file** transformer
 * sourcing its target path from
 * {@see \Waaseyaa\Bimaaji\Install\ClientCapabilityRegistry} instead of a
 * hardcoded `targetPath()` override — changed not one byte of what those
 * clients receive on disk.
 *
 * `claude` and `codex` are deliberately **not** covered here as of #2660
 * Part B: both now share {@see \Waaseyaa\Bimaaji\Install\Client\AbstractPerSkillClientTransformer},
 * which adds a source-inventory provenance footer to every per-skill file
 * and guidance index — an intentional, in-scope byte change (Codex also
 * moves delivery mode entirely), not the kind of accidental drift this
 * golden-snapshot test exists to catch. Their own transformer test classes
 * (`ClaudeClientTransformerTest`, `CodexClientTransformerTest`) cover their
 * current output, including the Claude/Codex per-skill byte-parity proof.
 *
 * `InstallTransformerGolden.php` is a literal snapshot of every transformer's
 * `targetFiles()` output (for both `InstallSkillFixtures::all()` and the
 * empty-skill-set case) captured from the PRE-refactor implementation. This
 * test re-runs every remaining single-file transformer through the CURRENT
 * implementation and diffs against that snapshot. A mismatch here means the
 * capability-registry seam changed shipped output, which #2660 Part A
 * explicitly forbids — see
 * `docs/adr/026-client-guidance-and-skill-conventions.md` for what a
 * deliberate output change requires.
 */
#[CoversNothing]
final class TransformerOutputRegressionTest extends TestCase
{
    /**
     * @return array<string, list<array{path: string, content: string, sourceSkill: string|null}>>
     */
    private static function golden(): array
    {
        /** @var array<string, list<array{path: string, content: string, sourceSkill: string|null}>> $golden */
        $golden = require __DIR__ . '/../../Fixture/InstallTransformerGolden.php';

        return $golden;
    }

    /**
     * @return array<string, array{0: ClientTransformerInterface}>
     */
    public static function transformers(): array
    {
        return [
            'copilot' => [new CopilotClientTransformer()],
            'cursor' => [new CursorClientTransformer()],
            'gemini' => [new GeminiClientTransformer()],
            'junie' => [new JunieClientTransformer()],
            'windsurf' => [new WindsurfClientTransformer()],
        ];
    }

    #[Test]
    #[DataProvider('transformers')]
    public function outputForTheFixtureSkillSetIsByteIdenticalToTheGoldenSnapshot(ClientTransformerInterface $transformer): void
    {
        $golden = self::golden();
        $expected = $golden[$transformer->clientId()] ?? null;
        self::assertNotNull($expected, sprintf('No golden entry for client "%s".', $transformer->clientId()));

        $actual = $this->serialize($transformer->targetFiles(InstallSkillFixtures::all()));

        self::assertSame($expected, $actual, sprintf(
            'Client "%s" produced output that differs from the pre-refactor golden snapshot.',
            $transformer->clientId(),
        ));
    }

    #[Test]
    #[DataProvider('transformers')]
    public function outputForAnEmptySkillSetIsByteIdenticalToTheGoldenSnapshot(ClientTransformerInterface $transformer): void
    {
        $golden = self::golden();
        $key = $transformer->clientId() . '__empty';
        $expected = $golden[$key] ?? null;
        self::assertNotNull($expected, sprintf('No golden entry for "%s".', $key));

        $actual = $this->serialize($transformer->targetFiles([]));

        self::assertSame($expected, $actual, sprintf(
            'Client "%s" produced empty-skill-set output that differs from the pre-refactor golden snapshot.',
            $transformer->clientId(),
        ));
    }

    /**
     * @param list<\Waaseyaa\Bimaaji\Install\TargetFile> $files
     * @return list<array{path: string, content: string, sourceSkill: string|null}>
     */
    private function serialize(array $files): array
    {
        return array_map(
            static fn(\Waaseyaa\Bimaaji\Install\TargetFile $file): array => [
                'path' => $file->path,
                'content' => $file->content,
                'sourceSkill' => $file->sourceSkill,
            ],
            $files,
        );
    }
}
