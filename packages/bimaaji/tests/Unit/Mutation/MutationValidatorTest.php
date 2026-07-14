<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Mutation;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Bimaaji\Graph\ApplicationGraph;
use Waaseyaa\Bimaaji\Graph\GraphSection;
use Waaseyaa\Bimaaji\Mutation\MutationRequest;
use Waaseyaa\Bimaaji\Mutation\MutationValidator;
use Waaseyaa\Bimaaji\Policy\SovereigntyGuardrails;
use Waaseyaa\Foundation\Sovereignty\SovereigntyProfile;

#[CoversClass(MutationValidator::class)]
final class MutationValidatorTest extends TestCase
{
    #[Test]
    public function it_gates_sovereignty_sensitive_operations_through_the_guardrails(): void
    {
        // On managed hosting (NorthOps), deleting an entity type is forbidden by
        // the sovereignty guardrails. The entity EXISTS, so the structural check
        // passes — only the guardrail blocks it. Pre-fix the validator never ran
        // the guardrails, so this returned success (the live bypass).
        $graph = $this->buildGraph(['node' => ['fields' => ['title' => []]]]);
        $validator = new MutationValidator(
            $graph,
            SovereigntyGuardrails::withDefaultRules(SovereigntyProfile::NorthOps),
        );

        $result = $validator->validate(new MutationRequest('delete_entity_type', 'node'));

        $this->assertFalse($result->isSuccess(), 'delete_entity_type must be blocked on managed hosting.');
        $this->assertContains('SOVEREIGNTY_VIOLATION', $result->errors);
    }

    #[Test]
    public function it_allows_sovereignty_sensitive_operations_on_a_permissive_profile(): void
    {
        // On a self-hosted/local profile the same operation is permitted (the
        // guardrail's denied profile does not match), so structural validation
        // alone decides.
        $graph = $this->buildGraph(['node' => ['fields' => ['title' => []]]]);
        $validator = new MutationValidator(
            $graph,
            SovereigntyGuardrails::withDefaultRules(SovereigntyProfile::Local),
        );

        $result = $validator->validate(new MutationRequest('delete_entity_type', 'node'));

        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function it_accepts_valid_entity_type_and_field(): void
    {
        $graph = $this->buildGraph(['node' => ['fields' => ['title' => [], 'body' => []]]]);
        $validator = new MutationValidator($graph);

        $request = new MutationRequest('add_field', 'node', 'body', ['type' => 'text']);
        $result = $validator->validate($request);

        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function it_rejects_unknown_entity_type(): void
    {
        $graph = $this->buildGraph(['node' => ['fields' => ['title' => []]]]);
        $validator = new MutationValidator($graph);

        $request = new MutationRequest('add_field', 'unknown_type', 'title');
        $result = $validator->validate($request);

        $this->assertFalse($result->isSuccess());
        $this->assertContains('UNKNOWN_ENTITY_TYPE', $result->errors);
    }

    #[Test]
    public function it_accepts_new_field_on_known_entity(): void
    {
        $graph = $this->buildGraph(['node' => ['fields' => ['title' => []]]]);
        $validator = new MutationValidator($graph);

        $request = new MutationRequest('add_field', 'node', 'new_field', ['type' => 'string']);
        $result = $validator->validate($request);

        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function it_rejects_path_breaking_entity_type_and_field_identifiers(): void
    {
        $graph = $this->buildGraph(['../node' => ['fields' => []]]);
        $validator = new MutationValidator($graph);

        $result = $validator->validate(new MutationRequest('add_field', '../node', '../../shell'));

        $this->assertFalse($result->isSuccess());
        $this->assertContains('INVALID_ENTITY_TYPE_FORMAT', $result->errors);
        $this->assertContains('INVALID_FIELD_FORMAT', $result->errors);
    }

    #[Test]
    public function it_accepts_entity_level_operations_without_field(): void
    {
        $graph = $this->buildGraph(['node' => ['fields' => []]]);
        $validator = new MutationValidator($graph);

        $request = new MutationRequest('add_entity_type', 'article', parameters: ['label' => 'Article']);
        $result = $validator->validate($request);

        // add_entity_type targets a new type — entity type check is skipped for creation ops
        $this->assertTrue($result->isSuccess());
    }

    #[Test]
    public function it_validates_without_entities_section(): void
    {
        $graph = new ApplicationGraph('1.0', []);
        $validator = new MutationValidator($graph);

        $request = new MutationRequest('add_field', 'node', 'title');
        $result = $validator->validate($request);

        $this->assertFalse($result->isSuccess());
        $this->assertContains('UNKNOWN_ENTITY_TYPE', $result->errors);
    }

    private function buildGraph(array $entities): ApplicationGraph
    {
        return new ApplicationGraph('1.0', [
            new GraphSection('entities', '1.0', $entities),
        ]);
    }
}
