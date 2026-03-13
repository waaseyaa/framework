<?php

declare(strict_types=1);

namespace Waaseyaa\Telescope\Tests\Unit\CodifiedContext\Validator;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Telescope\CodifiedContext\Validator\ContradictionChecker;
use Waaseyaa\Telescope\CodifiedContext\Validator\DriftScorer;
use Waaseyaa\Telescope\CodifiedContext\Validator\MockEmbeddingProvider;
use Waaseyaa\Telescope\CodifiedContext\Validator\StructuralChecker;
use Waaseyaa\Telescope\CodifiedContext\Validator\ValidationReport;

#[CoversClass(DriftScorer::class)]
final class DriftScorerTest extends TestCase
{
    private DriftScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new DriftScorer(
            embeddingProvider: new MockEmbeddingProvider(),
            structuralChecker: new StructuralChecker(),
            contradictionChecker: new ContradictionChecker(),
        );
    }

    #[Test]
    public function returns_validation_report(): void
    {
        $report = $this->scorer->score(
            modelOutput: 'Use EntityBase to create entities.',
            contextSections: ['EntityBase provides the base class for all entities.'],
            referencedFiles: [],
            existingFiles: [],
            contextFacts: [],
        );

        $this->assertInstanceOf(ValidationReport::class, $report);
    }

    #[Test]
    public function identical_text_scores_high(): void
    {
        $text = 'Use EntityBase to create entities with the correct constructor.';
        // Use fixed embeddings so identical text → cosine similarity 1.0
        $embedding = [1.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0];
        $provider = new MockEmbeddingProvider(fixedEmbeddings: [$text => $embedding]);
        $scorer = new DriftScorer(
            embeddingProvider: $provider,
            structuralChecker: new StructuralChecker(),
            contradictionChecker: new ContradictionChecker(),
        );

        $report = $scorer->score(
            modelOutput: $text,
            contextSections: [$text],
            referencedFiles: [],
            existingFiles: [],
            contextFacts: [],
        );

        // semantic: 60, structural: 20, contradiction: 20 = 100
        $this->assertSame(100, $report->driftScore);
    }

    #[Test]
    public function missing_files_reduce_score(): void
    {
        $report = $this->scorer->score(
            modelOutput: 'Some output.',
            contextSections: [],
            referencedFiles: ['src/Missing.php'],
            existingFiles: [],
            contextFacts: [],
        );

        // structural penalty: -4 → 16; semantic: 60 (no sections), contradiction: 20 → total 96
        $this->assertLessThan(100, $report->driftScore);
        $this->assertGreaterThanOrEqual(0, $report->driftScore);
    }

    #[Test]
    public function score_is_clamped_between_0_and_100(): void
    {
        $report = $this->scorer->score(
            modelOutput: 'Output',
            contextSections: [],
            referencedFiles: [],
            existingFiles: [],
            contextFacts: [],
        );

        $this->assertGreaterThanOrEqual(0, $report->driftScore);
        $this->assertLessThanOrEqual(100, $report->driftScore);
    }

    #[Test]
    public function deterministic_with_same_inputs(): void
    {
        $args = [
            'modelOutput' => 'Use the EntityBase class.',
            'contextSections' => ['EntityBase is the foundation class.'],
            'referencedFiles' => ['src/Entity/EntityBase.php'],
            'existingFiles' => ['src/Entity/EntityBase.php'],
            'contextFacts' => ['There are no magic numbers allowed'],
        ];

        $report1 = $this->scorer->score(...$args);
        $report2 = $this->scorer->score(...$args);

        $this->assertSame($report1->driftScore, $report2->driftScore);
        $this->assertSame($report1->semanticAlignment, $report2->semanticAlignment);
        $this->assertSame($report1->structuralScore, $report2->structuralScore);
        $this->assertSame($report1->contradictionScore, $report2->contradictionScore);
    }

    #[Test]
    public function issues_are_merged_from_checkers(): void
    {
        $report = $this->scorer->score(
            modelOutput: 'Use getter methods in your code.',
            contextSections: [],
            referencedFiles: ['src/Missing.php'],
            existingFiles: [],
            contextFacts: ['There are no getter methods available'],
        );

        $this->assertNotEmpty($report->issues);
        // Should contain issues from both structural and contradiction checkers
        $issueText = implode(' ', $report->issues);
        $this->assertStringContainsString('Missing', $issueText);
        $this->assertStringContainsString('Contradiction', $issueText);
    }

    #[Test]
    public function severity_from_score_low(): void
    {
        $this->assertSame('low', DriftScorer::severityFromScore(75));
        $this->assertSame('low', DriftScorer::severityFromScore(100));
        $this->assertSame('low', DriftScorer::severityFromScore(80));
    }

    #[Test]
    public function severity_from_score_medium(): void
    {
        $this->assertSame('medium', DriftScorer::severityFromScore(50));
        $this->assertSame('medium', DriftScorer::severityFromScore(74));
        $this->assertSame('medium', DriftScorer::severityFromScore(60));
    }

    #[Test]
    public function severity_from_score_high(): void
    {
        $this->assertSame('high', DriftScorer::severityFromScore(25));
        $this->assertSame('high', DriftScorer::severityFromScore(49));
        $this->assertSame('high', DriftScorer::severityFromScore(35));
    }

    #[Test]
    public function severity_from_score_critical(): void
    {
        $this->assertSame('critical', DriftScorer::severityFromScore(0));
        $this->assertSame('critical', DriftScorer::severityFromScore(24));
        $this->assertSame('critical', DriftScorer::severityFromScore(10));
    }

    #[Test]
    public function no_context_sections_gives_full_semantic_score(): void
    {
        // Fixed embeddings for structural check pass
        $report = $this->scorer->score(
            modelOutput: 'Any output.',
            contextSections: [],
            referencedFiles: [],
            existingFiles: [],
            contextFacts: [],
        );

        // semantic=60, structural=20, contradiction=20 → 100
        $this->assertSame(100, $report->driftScore);
        $this->assertSame(60.0, $report->semanticAlignment);
    }
}
