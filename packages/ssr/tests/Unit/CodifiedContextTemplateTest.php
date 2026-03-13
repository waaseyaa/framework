<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[CoversNothing]
final class CodifiedContextTemplateTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templatePath = dirname(__DIR__, 2) . '/templates/telescope/codified-context-session.html.twig';
        if (!file_exists($templatePath)) {
            $this->markTestSkipped('Codified context template not found');
        }

        $source = (string) file_get_contents($templatePath);

        // Strip the extends tag for isolated testing with ArrayLoader
        $source = preg_replace('/\{%[-\s]*extends\s+["\'][^"\']+["\']\s*[-\s]*%\}\s*/s', '', $source ?? '');
        // Unwrap block tags so content renders directly
        $source = preg_replace('/\{%[-\s]*block\s+\w+\s*[-\s]*%\}(.*?)\{%[-\s]*endblock\s*[-\s]*%\}/s', '$1', $source ?? '');

        $this->twig = new Environment(new ArrayLoader([
            'codified-context-session.html.twig' => $source,
        ]));
    }

    #[Test]
    public function renders_session_id(): void
    {
        $html = $this->twig->render('codified-context-session.html.twig', $this->sampleData());

        $this->assertStringContainsString('abc123def456ghi789', $html);
    }

    #[Test]
    public function renders_repo_hash(): void
    {
        $html = $this->twig->render('codified-context-session.html.twig', $this->sampleData());

        $this->assertStringContainsString('deadbeef1234', $html);
    }

    #[Test]
    public function renders_drift_score(): void
    {
        $html = $this->twig->render('codified-context-session.html.twig', $this->sampleData());

        $this->assertStringContainsString('Drift Score: 82/100', $html);
    }

    #[Test]
    public function renders_component_scores(): void
    {
        $html = $this->twig->render('codified-context-session.html.twig', $this->sampleData());

        $this->assertStringContainsString('Semantic Alignment', $html);
        $this->assertStringContainsString('Structural Checks', $html);
        $this->assertStringContainsString('Contradiction Checks', $html);
    }

    #[Test]
    public function renders_recommendation(): void
    {
        $html = $this->twig->render('codified-context-session.html.twig', $this->sampleData());

        $this->assertStringContainsString('Context is well-aligned', $html);
    }

    #[Test]
    public function renders_issues_when_present(): void
    {
        $html = $this->twig->render('codified-context-session.html.twig', $this->sampleData());

        $this->assertStringContainsString('[warning]', $html);
        $this->assertStringContainsString('Minor structural drift detected', $html);
    }

    #[Test]
    public function renders_event_stream(): void
    {
        $html = $this->twig->render('codified-context-session.html.twig', $this->sampleData());

        $this->assertStringContainsString('Event Stream', $html);
        $this->assertStringContainsString('spec_loaded', $html);
    }

    #[Test]
    public function renders_event_data_as_json(): void
    {
        $html = $this->twig->render('codified-context-session.html.twig', $this->sampleData());

        $this->assertStringContainsString('entity-system', $html);
    }

    #[Test]
    public function omits_validation_block_when_no_validation(): void
    {
        $data = $this->sampleData();
        $data['validation'] = null;

        $html = $this->twig->render('codified-context-session.html.twig', $data);

        $this->assertStringNotContainsString('Drift Score', $html);
        $this->assertStringNotContainsString('drift-score', $html);
    }

    #[Test]
    public function omits_event_table_when_no_events(): void
    {
        $data = $this->sampleData();
        $data['events'] = [];

        $html = $this->twig->render('codified-context-session.html.twig', $data);

        $this->assertStringNotContainsString('Event Stream', $html);
        $this->assertStringNotContainsString('<table>', $html);
    }

    #[Test]
    public function renders_duration_when_session_ended(): void
    {
        $data = $this->sampleData();
        $data['session']['endedAt'] = '2026-03-12T10:05:00Z';
        $data['session']['durationMs'] = 300000;

        $html = $this->twig->render('codified-context-session.html.twig', $data);

        $this->assertStringContainsString('Duration', $html);
        $this->assertStringContainsString('300.0s', $html);
    }

    /**
     * @return array<string, mixed>
     */
    private function sampleData(): array
    {
        return [
            'session' => [
                'sessionId' => 'abc123def456ghi789',
                'repoHash' => 'deadbeef1234',
                'startedAt' => '2026-03-12T10:00:00Z',
                'endedAt' => null,
                'durationMs' => null,
                'eventCount' => 3,
            ],
            'validation' => [
                'driftScore' => 82,
                'components' => [
                    'semantic_alignment' => 49.2,
                    'structural_checks' => 18.0,
                    'contradiction_checks' => 14.8,
                ],
                'issues' => [
                    ['severity' => 'warning', 'message' => 'Minor structural drift detected'],
                ],
                'recommendation' => 'Context is well-aligned',
            ],
            'events' => [
                [
                    'createdAt' => '2026-03-12T10:00:01Z',
                    'eventType' => 'spec_loaded',
                    'data' => ['spec' => 'entity-system', 'tier' => 3],
                ],
            ],
        ];
    }
}
