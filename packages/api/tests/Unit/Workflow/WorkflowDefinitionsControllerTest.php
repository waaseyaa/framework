<?php

declare(strict_types=1);

namespace Waaseyaa\Api\Tests\Unit\Workflow;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Api\Workflow\WorkflowDefinitionsController;
use Waaseyaa\Workflows\EditorialWorkflowPreset;
use Waaseyaa\Workflows\Workflow;

#[CoversClass(WorkflowDefinitionsController::class)]
final class WorkflowDefinitionsControllerTest extends TestCase
{
    #[Test]
    public function listReturnsWellFormedEmptyResultByDefault(): void
    {
        // #2835: replaces the retired pin on EditorialWorkflowPreset — with
        // no provider (an unwired install), the controller must yield a
        // well-formed empty result, never a fictional in-code preset that
        // has already drifted from the live editorial transition set.
        $payload = (new WorkflowDefinitionsController())->list();

        self::assertSame(['data' => []], $payload);
    }

    #[Test]
    public function listSerializesStateShape(): void
    {
        $controller = new WorkflowDefinitionsController(
            static fn(): array => [EditorialWorkflowPreset::create()],
        );
        $payload = $controller->list();
        $stateIds = array_column($payload['data'][0]['states'], 'id');

        self::assertSame(['draft', 'review', 'published', 'archived'], $stateIds);

        $draft = $payload['data'][0]['states'][0];
        self::assertSame('draft', $draft['id']);
        self::assertSame('Draft', $draft['label']);
        self::assertSame(0, $draft['weight']);
        self::assertSame(['legacy_status' => 0], $draft['metadata']);
    }

    #[Test]
    public function listSerializesTransitionShape(): void
    {
        $controller = new WorkflowDefinitionsController(
            static fn(): array => [EditorialWorkflowPreset::create()],
        );
        $payload = $controller->list();
        $submit = $payload['data'][0]['transitions'][0];

        self::assertSame('submit_for_review', $submit['id']);
        self::assertSame('Submit for Review', $submit['label']);
        self::assertSame(['draft'], $submit['from']);
        self::assertSame('review', $submit['to']);
    }

    #[Test]
    public function listRespectsInjectedWorkflowsProvider(): void
    {
        $custom = new Workflow([
            'id' => 'custom',
            'label' => 'Custom',
            'states' => ['open' => ['label' => 'Open', 'weight' => 0]],
            'transitions' => [],
        ]);

        $controller = new WorkflowDefinitionsController(
            static fn(): array => [EditorialWorkflowPreset::create(), $custom],
        );
        $payload = $controller->list();

        self::assertCount(2, $payload['data']);
        self::assertSame('custom', $payload['data'][1]['id']);
        self::assertSame([['id' => 'open', 'label' => 'Open', 'weight' => 0, 'metadata' => []]], $payload['data'][1]['states']);
        self::assertSame([], $payload['data'][1]['transitions']);
    }

    #[Test]
    public function listProducesEmptyDataForEmptyProvider(): void
    {
        $controller = new WorkflowDefinitionsController(static fn(): array => []);
        $payload = $controller->list();

        self::assertSame(['data' => []], $payload);
    }
}
