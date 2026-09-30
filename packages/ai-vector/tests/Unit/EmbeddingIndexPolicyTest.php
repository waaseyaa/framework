<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Vector\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Vector\EmbeddingIndexPolicy;
use Waaseyaa\AI\Vector\EmbeddingProviderEgressInterface;
use Waaseyaa\AI\Vector\EmbeddingProviderInterface;
use Waaseyaa\AI\Vector\InvalidEmbeddingIndexPolicyException;
use Waaseyaa\Entity\EntityInterface;

#[CoversClass(EmbeddingIndexPolicy::class)]
final class EmbeddingIndexPolicyTest extends TestCase
{
    #[Test]
    public function missing_policy_denies_every_entity_type(): void
    {
        $policy = EmbeddingIndexPolicy::fromArray([]);

        self::assertNull($policy->embeddingText(
            new PolicyEntity('user', ['name' => 'Private name']),
            $this->provider(leavesHost: false),
        ));
    }

    #[Test]
    public function projection_contains_only_declared_fields_without_an_implicit_label(): void
    {
        $policy = $this->policy([
            'node' => [
                'fields' => ['title', 'body'],
                'allow_external' => false,
            ],
        ]);

        $text = $policy->embeddingText(
            new PolicyEntity('node', [
                'status' => 1,
                'workflow_state' => 'published',
                'title' => 'Public title',
                'body' => 'Public body',
                'email' => 'private@example.test',
            ], label: 'Implicit label'),
            $this->provider(leavesHost: false),
        );

        self::assertSame("Public title\n\nPublic body", $text);
    }

    #[Test]
    public function off_host_provider_requires_explicit_permission(): void
    {
        $entity = new PolicyEntity('node', [
            'status' => 1,
            'workflow_state' => 'published',
            'title' => 'Public title',
        ]);
        $external = $this->provider(leavesHost: true);

        self::assertNull($this->policy([
            'node' => ['fields' => ['title'], 'allow_external' => false],
        ])->embeddingText($entity, $external));
        self::assertSame('Public title', $this->policy([
            'node' => ['fields' => ['title'], 'allow_external' => true],
        ])->embeddingText($entity, $external));
    }

    #[Test]
    public function an_unclassified_provider_is_treated_as_off_host(): void
    {
        $provider = new class implements EmbeddingProviderInterface {
            public function embed(string $text): array { return [1.0]; }
        };
        $entity = new PolicyEntity('node', [
            'status' => 1,
            'workflow_state' => 'published',
            'title' => 'Public title',
        ]);

        self::assertNull($this->policy([
            'node' => ['fields' => ['title'], 'allow_external' => false],
        ])->embeddingText($entity, $provider));
    }

    #[Test]
    public function malformed_policy_refuses_startup(): void
    {
        $this->expectException(InvalidEmbeddingIndexPolicyException::class);
        $this->expectExceptionMessage('[AIV-POLICY-001]');

        EmbeddingIndexPolicy::fromArray([
            'ai' => ['vector_index' => ['node' => ['fields' => 'title']]],
        ]);
    }

    /** @param array<string, array{fields: list<string>, allow_external: bool}> $rules */
    private function policy(array $rules): EmbeddingIndexPolicy
    {
        return EmbeddingIndexPolicy::fromArray(['ai' => ['vector_index' => $rules]]);
    }

    private function provider(bool $leavesHost): EmbeddingProviderInterface
    {
        return new readonly class ($leavesHost) implements EmbeddingProviderInterface, EmbeddingProviderEgressInterface {
            public function __construct(private bool $leavesHost) {}
            public function embed(string $text): array { return [1.0]; }
            public function transmitsOffHost(): bool { return $this->leavesHost; }
        };
    }
}

final readonly class PolicyEntity implements EntityInterface
{
    /** @param array<string, mixed> $values */
    public function __construct(
        private string $entityTypeId,
        private array $values,
        private string $label = '',
    ) {}

    public function id(): int|string|null { return 1; }
    public function uuid(): string { return 'uuid'; }
    public function label(): string { return $this->label; }
    public function getEntityTypeId(): string { return $this->entityTypeId; }
    public function bundle(): string { return 'default'; }
    public function isNew(): bool { return false; }
    public function get(string $name): mixed { return $this->values[$name] ?? null; }
    public function set(string $name, mixed $value): static { throw new \LogicException('Readonly'); }
    public function toArray(): array { return $this->values; }
    public function language(): string { return 'en'; }
}
