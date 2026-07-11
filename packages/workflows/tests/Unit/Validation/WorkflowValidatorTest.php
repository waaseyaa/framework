<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Tests\Unit\Validation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Workflows\Validation\WorkflowValidator;
use Waaseyaa\Workflows\Workflow;

/**
 * @covers \Waaseyaa\Workflows\Validation\WorkflowValidator
 */
#[CoversClass(WorkflowValidator::class)]
final class WorkflowValidatorTest extends TestCase
{
    #[Test]
    public function a_transition_from_an_unknown_state_is_a_violation(): void
    {
        $workflow = new Workflow(['id' => 'w',
            'states' => ['draft' => ['label' => 'Draft']],
            'transitions' => ['bad' => ['label' => 'Bad', 'from' => ['nope'], 'to' => 'draft']]]);

        $violations = (new WorkflowValidator())->validate($workflow);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("unknown state 'nope'", $violations[0]);
    }

    #[Test]
    public function a_transition_to_an_unknown_state_is_a_violation(): void
    {
        $workflow = new Workflow(['id' => 'w',
            'states' => ['draft' => ['label' => 'Draft']],
            'transitions' => ['bad' => ['label' => 'Bad', 'from' => ['draft'], 'to' => 'nope']]]);

        $violations = (new WorkflowValidator())->validate($workflow);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("unknown state 'nope'", $violations[0]);
    }

    #[Test]
    public function an_initial_state_not_among_the_defined_states_is_a_violation(): void
    {
        $workflow = new Workflow(['id' => 'w', 'initial_state' => 'nope',
            'states' => ['draft' => ['label' => 'Draft']]]);

        $violations = (new WorkflowValidator())->validate($workflow);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("initial_state 'nope'", $violations[0]);
    }

    #[Test]
    public function zero_states_is_a_violation(): void
    {
        $workflow = new Workflow(['id' => 'w']);

        $violations = (new WorkflowValidator())->validate($workflow);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString('zero states', $violations[0]);
    }

    #[Test]
    public function a_valid_workflow_has_no_violations(): void
    {
        $workflow = new Workflow(['id' => 'w', 'initial_state' => 'draft',
            'states' => ['draft' => ['label' => 'Draft'], 'published' => ['label' => 'Published', 'published' => true]],
            'transitions' => ['publish' => ['label' => 'Publish', 'from' => ['draft'], 'to' => 'published']]]);

        $this->assertSame([], (new WorkflowValidator())->validate($workflow));
    }

    #[Test]
    public function a_transition_with_no_group_constraint_is_not_a_violation(): void
    {
        $workflow = new Workflow(['id' => 'w',
            'states' => ['draft' => ['label' => 'Draft'], 'published' => ['label' => 'Published']],
            'transitions' => ['publish' => ['label' => 'Publish', 'from' => ['draft'], 'to' => 'published']]]);

        $this->assertSame([], (new WorkflowValidator())->validate($workflow));
    }

    #[Test]
    public function a_transition_with_the_content_groups_constraint_is_valid(): void
    {
        $workflow = new Workflow(['id' => 'w',
            'states' => ['draft' => ['label' => 'Draft'], 'published' => ['label' => 'Published']],
            'transitions' => ['publish' => ['label' => 'Publish', 'from' => ['draft'], 'to' => 'published',
                'group_constraint' => 'content_groups']]]);

        $this->assertSame([], (new WorkflowValidator())->validate($workflow));
    }

    #[Test]
    public function a_transition_with_an_unknown_group_constraint_kind_is_a_violation(): void
    {
        $workflow = new Workflow(['id' => 'w',
            'states' => ['draft' => ['label' => 'Draft'], 'published' => ['label' => 'Published']],
            'transitions' => ['publish' => ['label' => 'Publish', 'from' => ['draft'], 'to' => 'published',
                'group_constraint' => 'bogus_kind']]]);

        $violations = (new WorkflowValidator())->validate($workflow);

        $this->assertCount(1, $violations);
        $this->assertStringContainsString("'publish'", $violations[0]);
        $this->assertStringContainsString("'bogus_kind'", $violations[0]);
    }
}
