<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Architecture;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;
use Waaseyaa\Tooling\SpecCorpus\SpecChunker;
use Waaseyaa\Tooling\SpecCorpus\SpecCorpusCompiler;
use Waaseyaa\Tooling\SpecCorpus\SpecCorpusException;
use Waaseyaa\Tooling\SpecCorpus\SpecSanitizer;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../../tools/lib/SpecCorpus/SpecCorpusException.php';
require_once __DIR__ . '/../../tools/lib/SpecCorpus/SpecLifecycle.php';
require_once __DIR__ . '/../../tools/lib/SpecCorpus/SpecCorpusGuard.php';
require_once __DIR__ . '/../../tools/lib/SpecCorpus/SpecFrontmatter.php';
require_once __DIR__ . '/../../tools/lib/SpecCorpus/SpecSanitizer.php';
require_once __DIR__ . '/../../tools/lib/SpecCorpus/SpecChunker.php';
require_once __DIR__ . '/../../tools/lib/SpecCorpus/SpecCorpusCompiler.php';

/**
 * #2661: authoritative specs compile into a sanitized, versioned agent corpus
 * with lifecycle metadata, live-only default index, and fail-closed supersession.
 */
#[CoversNothing]
final class SpecCorpusCompilerTest extends TestCase
{
    private string $repoRoot;

    private string $gate;

    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->repoRoot = dirname(__DIR__, 2);
        $this->gate = $this->repoRoot . '/bin/compile-spec-corpus';
        self::assertFileExists($this->gate);
        $this->tmpRoot = sys_get_temp_dir() . '/waaseyaa_spec_corpus_' . uniqid('', true);
        mkdir($this->tmpRoot . '/docs/specs', 0o755, true);
        mkdir($this->tmpRoot . '/tools', 0o755, true);
        file_put_contents($this->tmpRoot . '/VERSION', "0.1.0-alpha.test\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->tmpRoot);
    }

    #[Test]
    public function sanitizer_strips_spec_reviewed_comments_and_internal_links_but_retains_provenance(): void
    {
        $body = <<<'MD'
            <!-- Spec reviewed 2026-09-05 - #2641: nightly scan only. -->
            # Live contract

            See [mission plan](kitty-specs/foo/spec.md) and [history](docs/history/plans/bar.md).

            Body text remains.
            MD;

        $result = SpecSanitizer::sanitize($body);

        self::assertStringNotContainsString('Spec reviewed', $result['retrieval_text']);
        self::assertStringNotContainsString('kitty-specs/', $result['retrieval_text']);
        self::assertStringContainsString('mission plan', $result['retrieval_text']);
        self::assertStringContainsString('Body text remains.', $result['retrieval_text']);
        self::assertCount(1, $result['provenance']['spec_reviewed_comments']);
        self::assertCount(2, $result['provenance']['internal_links']);
    }

    #[Test]
    public function sanitizer_strips_docs_specs_relative_history_links_and_preserves_external_links(): void
    {
        $body = <<<'MD'
            # Links

            From docs/specs, see [history](../history/plans/bar.md) and [external](https://example.com/spec).

            Also [anchor](#section-one).
            MD;

        $result = SpecSanitizer::sanitize($body);

        self::assertStringContainsString('[external](https://example.com/spec)', $result['retrieval_text']);
        self::assertStringContainsString('[anchor](#section-one)', $result['retrieval_text']);
        self::assertStringNotContainsString('../history/', $result['retrieval_text']);
        self::assertStringContainsString('history', $result['retrieval_text']);
        self::assertCount(1, $result['provenance']['internal_links']);
        self::assertSame('../history/plans/bar.md', $result['provenance']['internal_links'][0]['target']);
    }

    #[Test]
    public function sanitizer_records_reference_style_links_as_unsupported_without_stripping_them(): void
    {
        $body = <<<'MD'
            # Refs

            See [history][hist-ref] for context.
            MD;

        $result = SpecSanitizer::sanitize($body);

        self::assertStringContainsString('[history][hist-ref]', $result['retrieval_text']);
        self::assertSame(['[history][hist-ref]'], $result['provenance']['unsupported_reference_links']);
    }

    #[Test]
    public function chunker_records_heading_level_and_stable_ids_for_each_section(): void
    {
        $chunks = SpecChunker::chunk(
            'live-spec',
            <<<'MD'
                # Title

                Preamble body.

                ## First section

                Alpha body.

                ### Nested detail

                Beta body.

                ## Second section

                Gamma body.
                MD
        );

        self::assertCount(4, $chunks);
        self::assertSame('', $chunks[0]['heading']);
        self::assertSame(0, $chunks[0]['level']);
        self::assertSame('First section', $chunks[1]['heading']);
        self::assertSame(2, $chunks[1]['level']);
        self::assertSame('live-spec#first-section', $chunks[1]['id']);
        self::assertSame('Nested detail', $chunks[2]['heading']);
        self::assertSame(3, $chunks[2]['level']);
        self::assertSame('Second section', $chunks[3]['heading']);
        self::assertSame(2, $chunks[3]['level']);
    }

    #[Test]
    public function inserting_an_unrelated_section_preserves_other_unique_heading_ids(): void
    {
        $before = SpecChunker::chunk(
            'live-spec',
            <<<'MD'
                ## Alpha

                Alpha body.

                ## Beta

                Beta body.
                MD
        );

        $after = SpecChunker::chunk(
            'live-spec',
            <<<'MD'
                ## Alpha

                Alpha body.

                ## Gamma

                Gamma body.

                ## Beta

                Beta body.
                MD
        );

        self::assertSame($before[0]['id'], $after[0]['id']);
        self::assertSame($before[1]['id'], $after[2]['id']);
        self::assertSame('live-spec#alpha', $before[0]['id']);
        self::assertSame('live-spec#beta', $before[1]['id']);
        self::assertSame('live-spec#gamma', $after[1]['id']);
    }

    #[Test]
    public function colliding_heading_slugs_still_have_unique_chunk_ids(): void
    {
        $chunks = SpecChunker::chunk('doc', "## Alpha\nA\n## Alpha\nB\n## Alpha-2\nC\n");
        $ids = array_column($chunks, 'id');
        self::assertCount(3, $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    #[Test]
    public function internal_reference_definition_is_refused_instead_of_leaking_to_retrieval(): void
    {
        $this->expectException(SpecCorpusException::class);
        SpecSanitizer::sanitize("See [history][old].\n\n[old]: ../history/plans/old.md\n");
    }

    #[Test]
    public function manifest_document_id_traversal_is_rejected_and_writes_nothing_outside_output(): void
    {
        $reviewRoot = sys_get_temp_dir() . '/waaseyaa-corpus-review-' . uniqid('', true);
        $output = $reviewRoot . '/out';
        mkdir($reviewRoot . '/docs/specs', 0o755, true);
        mkdir($reviewRoot . '/tools', 0o755, true);
        file_put_contents($reviewRoot . '/VERSION', "0.1.0-alpha.test\n");
        file_put_contents($reviewRoot . '/docs/specs/escaped.md', "# Escaped\n\nBody.\n");
        file_put_contents($reviewRoot . '/tools/manifest.json', json_encode([
            'corpus_version' => '1',
            'specs' => [
                ['id' => '../../escaped', 'path' => 'docs/specs/escaped.md', 'lifecycle' => 'live'],
            ],
        ], JSON_THROW_ON_ERROR));

        [$exit, $outputText] = $this->runGate($output, $reviewRoot . '/tools/manifest.json', $reviewRoot);

        self::assertSame(2, $exit, $outputText);
        self::assertStringContainsString('Invalid document id', $outputText);
        self::assertFileDoesNotExist(dirname($reviewRoot) . '/escaped.json');
        self::assertFileDoesNotExist($output);

        new Filesystem()->remove($reviewRoot);
    }

    #[Test]
    public function spec_path_outside_docs_specs_is_rejected(): void
    {
        mkdir($this->tmpRoot . '/docs/history/plans', 0o755, true);
        file_put_contents($this->tmpRoot . '/docs/history/plans/outside.md', "# Outside\n\nNope.\n");
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'outside', 'path' => 'docs/history/plans/outside.md', 'lifecycle' => 'live'],
            ],
        ]);

        $this->expectException(SpecCorpusException::class);
        $this->expectExceptionMessage('docs/specs/');

        SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');
    }

    #[Test]
    public function symlink_escape_under_docs_specs_is_rejected(): void
    {
        self::assertTrue(function_exists('symlink'), 'Linux Architecture proof requires symlink() support.');

        mkdir($this->tmpRoot . '/docs/history/plans', 0o755, true);
        file_put_contents($this->tmpRoot . '/docs/history/plans/secret.md', "# Secret\n\nNo.\n");
        self::assertTrue(symlink(
            $this->tmpRoot . '/docs/history/plans/secret.md',
            $this->tmpRoot . '/docs/specs/link.md',
        ));

        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'link', 'path' => 'docs/specs/link.md', 'lifecycle' => 'live'],
            ],
        ]);

        $this->expectException(SpecCorpusException::class);
        $this->expectExceptionMessage('Symlink');

        SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');
    }

    #[Test]
    public function docs_parent_symlink_escape_is_rejected(): void
    {
        self::assertTrue(function_exists('symlink'), 'Linux Architecture proof requires symlink() support.');

        $outside = $this->tmpRoot . '/outside-specs';
        mkdir($outside, 0o755, true);
        file_put_contents($outside . '/escape.md', "# Escape\n\nNo.\n");
        rename($this->tmpRoot . '/docs/specs', $this->tmpRoot . '/docs/specs-real');
        self::assertTrue(symlink($outside, $this->tmpRoot . '/docs/specs'));

        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'escape', 'path' => 'docs/specs/escape.md', 'lifecycle' => 'live'],
            ],
        ]);

        $this->expectException(SpecCorpusException::class);
        $this->expectExceptionMessage('symlink');

        SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');
    }

    #[Test]
    public function duplicate_document_ids_are_rejected(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live'],
                ['id' => 'live-spec', 'path' => 'docs/specs/other-spec.md', 'lifecycle' => 'live'],
            ],
        ]);
        $this->writeSpec('live-spec.md', "# Live\n\nCurrent.\n");
        $this->writeSpec('other-spec.md', "# Other\n\nAlso.\n");

        $this->expectException(SpecCorpusException::class);
        $this->expectExceptionMessage('Duplicate manifest document id');

        SpecCorpusCompiler::loadManifest($this->tmpRoot . '/tools/manifest.json');
    }

    #[Test]
    public function unsupported_corpus_version_is_rejected(): void
    {
        $this->writeManifest([
            'corpus_version' => '2',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live'],
            ],
        ]);
        $this->writeSpec('live-spec.md', "# Live\n\nBody.\n");

        $this->expectException(SpecCorpusException::class);
        $this->expectExceptionMessage("Unsupported corpus_version '2'");

        SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');
    }

    #[Test]
    public function conflicting_frontmatter_and_manifest_lifecycle_fails_closed(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'mixed-spec', 'path' => 'docs/specs/mixed-spec.md', 'lifecycle' => 'draft'],
            ],
        ]);
        $this->writeSpec(
            'mixed-spec.md',
            <<<'MD'
                ---
                waaseyaa-spec:
                  lifecycle: live
                ---
                # Mixed

                Conflict.
                MD
        );

        $this->expectException(SpecCorpusException::class);
        $this->expectExceptionMessage('conflicting lifecycle');

        SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');
    }

    #[Test]
    public function lifecycle_frontmatter_with_manifest_title_and_h1_does_not_false_conflict(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                [
                    'id' => 'mixed-spec',
                    'path' => 'docs/specs/mixed-spec.md',
                    'lifecycle' => 'live',
                    'title' => 'Curated manifest title',
                ],
            ],
        ]);
        $this->writeSpec(
            'mixed-spec.md',
            <<<'MD'
                ---
                waaseyaa-spec:
                  lifecycle: live
                ---
                # Different H1 title

                Body.
                MD
        );

        $compiled = SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');

        self::assertSame('Curated manifest title', $compiled['documents']['mixed-spec']['title']);
    }

    #[Test]
    public function undeclared_frontmatter_uses_manifest_title_then_h1_fallback(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'titled-spec', 'path' => 'docs/specs/titled-spec.md', 'lifecycle' => 'live'],
            ],
        ]);
        $this->writeSpec('titled-spec.md', "# Parsed H1 title\n\nBody.\n");

        $compiled = SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');

        self::assertSame('Parsed H1 title', $compiled['documents']['titled-spec']['title']);
    }

    #[Test]
    public function frontmatter_and_manifest_agree_on_lifecycle(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'mixed-spec', 'path' => 'docs/specs/mixed-spec.md', 'lifecycle' => 'live'],
            ],
        ]);
        $this->writeSpec(
            'mixed-spec.md',
            <<<'MD'
                ---
                waaseyaa-spec:
                  lifecycle: live
                ---
                # Mixed

                Agreed.
                MD
        );

        $compiled = SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');

        self::assertSame('live', $compiled['documents']['mixed-spec']['lifecycle']);
        self::assertSame(['mixed-spec'], array_column($compiled['index']['entries'], 'id'));
        self::assertSame('Mixed', $compiled['documents']['mixed-spec']['title']);
    }

    #[Test]
    public function malformed_yaml_frontmatter_fails_closed(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'bad-spec', 'path' => 'docs/specs/bad-spec.md', 'lifecycle' => 'live'],
            ],
        ]);
        $this->writeSpec(
            'bad-spec.md',
            <<<'MD'
                ---
                waaseyaa-spec: "not-a-mapping"
                ---
                # Bad
                MD
        );

        $this->expectException(SpecCorpusException::class);
        $this->expectExceptionMessage('waaseyaa-spec must be a mapping');

        SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');
    }

    #[Test]
    public function default_index_includes_live_material_only(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live'],
                ['id' => 'draft-spec', 'path' => 'docs/specs/draft-spec.md', 'lifecycle' => 'draft'],
                ['id' => 'old-spec', 'path' => 'docs/specs/old-spec.md', 'lifecycle' => 'historical'],
                [
                    'id' => 'retired-spec',
                    'path' => 'docs/specs/retired-spec.md',
                    'lifecycle' => 'superseded',
                    'superseded_by' => 'live-spec',
                ],
            ],
        ]);
        $this->writeSpec('live-spec.md', "# Live\n\nCurrent.\n");
        $this->writeSpec('draft-spec.md', "# Draft\n\nWIP.\n");
        $this->writeSpec('old-spec.md', "# Historical\n\nAudit.\n");
        $this->writeSpec('retired-spec.md', "# Retired\n\nOld.\n");

        $compiled = SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');

        self::assertSame(['live-spec'], array_column($compiled['index']['entries'], 'id'));
        self::assertCount(4, $compiled['documents']);
    }

    #[Test]
    public function superseded_without_superseded_by_fails_closed(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live'],
                ['id' => 'retired-spec', 'path' => 'docs/specs/retired-spec.md', 'lifecycle' => 'superseded'],
            ],
        ]);
        $this->writeSpec('live-spec.md', "# Live\n\nCurrent.\n");
        $this->writeSpec('retired-spec.md', "# Retired\n\nOld.\n");

        $this->expectException(SpecCorpusException::class);
        $this->expectExceptionMessage('missing superseded_by');

        SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');
    }

    #[Test]
    public function compilation_is_deterministic_for_unchanged_inputs(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live', 'title' => 'Live title'],
            ],
        ]);
        $this->writeSpec('live-spec.md', "# Live\n\n## Section\n\nStable body.\n");

        $first = SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');
        $second = SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');

        self::assertSame($first['manifest']['corpus_digest'], $second['manifest']['corpus_digest']);
        self::assertSame($first['documents']['live-spec']['chunks'], $second['documents']['live-spec']['chunks']);
        SpecCorpusCompiler::verifyCompiledDigest($first);
    }

    #[Test]
    public function corpus_digest_binds_framework_version_and_title_metadata(): void
    {
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live', 'title' => 'Alpha'],
            ],
        ]);
        $this->writeSpec('live-spec.md', "# Live\n\nBody.\n");

        $alpha = SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');

        file_put_contents($this->tmpRoot . '/tools/manifest.json', json_encode([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live', 'title' => 'Beta'],
            ],
        ], JSON_THROW_ON_ERROR));

        $beta = SpecCorpusCompiler::compile($this->tmpRoot, $this->readManifest(), '0.1.0-alpha.test');

        self::assertNotSame($alpha['manifest']['corpus_digest'], $beta['manifest']['corpus_digest']);
    }

    #[Test]
    public function bin_compile_spec_corpus_writes_manifest_index_and_documents(): void
    {
        $output = $this->tmpRoot . '/out';
        $manifest = $this->tmpRoot . '/tools/manifest.json';
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live'],
            ],
        ], $manifest);
        $this->writeSpec('live-spec.md', "# Live\n\nBody.\n");

        [$exit, $stdout] = $this->runGate($output, $manifest);

        self::assertSame(0, $exit, $stdout);
        self::assertFileExists($output . '/manifest.json');
        self::assertFileExists($output . '/index.json');
        self::assertFileExists($output . '/documents/live-spec.json');
        self::assertStringContainsString('compile-spec-corpus: OK', $stdout);
    }

    #[Test]
    public function failed_generation_leaves_output_target_absent(): void
    {
        $output = $this->tmpRoot . '/out';
        $manifest = $this->tmpRoot . '/tools/manifest.json';
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live'],
                ['id' => 'retired-spec', 'path' => 'docs/specs/retired-spec.md', 'lifecycle' => 'superseded'],
            ],
        ], $manifest);
        $this->writeSpec('live-spec.md', "# Live\n\nBody.\n");
        $this->writeSpec('retired-spec.md', "# Retired\n\nOld.\n");

        [$exit] = $this->runGate($output, $manifest);

        self::assertSame(2, $exit);
        self::assertFileDoesNotExist($output);
    }

    #[Test]
    public function publication_refuses_any_existing_output_target_including_empty_directory(): void
    {
        $output = $this->tmpRoot . '/out';
        mkdir($output, 0o755, true);

        $manifest = $this->tmpRoot . '/tools/manifest.json';
        $this->writeManifest([
            'corpus_version' => '1',
            'specs' => [
                ['id' => 'live-spec', 'path' => 'docs/specs/live-spec.md', 'lifecycle' => 'live'],
            ],
        ], $manifest);
        $this->writeSpec('live-spec.md', "# Live\n\nBody.\n");

        [$exit, $stderr] = $this->runGate($output, $manifest);

        self::assertSame(2, $exit, $stderr);
        self::assertStringContainsString('Output target already exists', $stderr);
        self::assertFileDoesNotExist($output . '/manifest.json');
    }

    #[Test]
    public function pilot_manifest_compiles_against_real_repository_specs(): void
    {
        $manifest = $this->repoRoot . '/tools/spec-corpus-pilot-manifest.json';
        self::assertFileExists($manifest);

        $compiled = SpecCorpusCompiler::compile(
            $this->repoRoot,
            SpecCorpusCompiler::loadManifest($manifest),
        );

        self::assertGreaterThanOrEqual(4, count($compiled['index']['entries']));
        self::assertArrayHasKey('entity-storage-two-axis', $compiled['documents']);
        self::assertSame('superseded', $compiled['documents']['entity-storage-two-axis']['lifecycle']);
        self::assertSame(
            'revision-system-unified',
            $compiled['documents']['entity-storage-two-axis']['superseded_by'],
        );
        self::assertArrayNotHasKey('entity-storage-two-axis', array_column($compiled['index']['entries'], 'id', 'id'));
        self::assertArrayHasKey('spec-corpus', $compiled['documents']);
        self::assertSame('draft', $compiled['documents']['spec-corpus']['lifecycle']);
        self::assertSame(
            'Specification corpus compiler',
            $compiled['documents']['spec-corpus']['title'],
        );
        self::assertNotEmpty(
            array_filter(
                $compiled['documents']['revision-system-unified']['chunks'],
                static fn(array $chunk): bool => $chunk['heading'] !== '',
            ),
        );
        SpecCorpusCompiler::verifyCompiledDigest($compiled);
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function writeManifest(array $manifest, ?string $path = null): void
    {
        $path ??= $this->tmpRoot . '/tools/manifest.json';
        file_put_contents($path, json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function readManifest(): array
    {
        return SpecCorpusCompiler::loadManifest($this->tmpRoot . '/tools/manifest.json');
    }

    private function writeSpec(string $filename, string $content): void
    {
        file_put_contents($this->tmpRoot . '/docs/specs/' . $filename, $content);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runGate(string $outputDir, string $manifestFile, ?string $root = null): array
    {
        $process = new Process([
            PHP_BINARY,
            $this->gate,
            '--root',
            $root ?? $this->tmpRoot,
            '--manifest',
            $manifestFile,
            '--output',
            $outputDir,
        ]);
        $process->run();

        return [$process->getExitCode() ?? 1, $process->getOutput() . $process->getErrorOutput()];
    }
}
