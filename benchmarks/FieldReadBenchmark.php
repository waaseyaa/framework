<?php

declare(strict_types=1);

namespace Waaseyaa\Benchmarks;

use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\AuthorizationPrincipalInterface;
use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityIssueContext;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\InMemoryCapabilityRegistry;
use Waaseyaa\Access\Context\AccountFieldReadScope;
use Waaseyaa\Access\FieldReadGuard;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\PrivilegedReadDescriptor;
use Waaseyaa\Audit\Contract\PrivilegedReadOutcome;
use Waaseyaa\Audit\Contract\PrivilegedReadReceipt;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\EntityBase;
use Waaseyaa\Entity\EntityStructure;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Field\FieldDefinition;
use Waaseyaa\Field\FieldReadMetadataResolver;

/**
 * Reproducible WP3 evidence for the dormant accessor-activation design.
 *
 * Allocation proxy is the fixture's peak-memory increase over its starting
 * memory. PHP does not expose an allocation counter without an extension, so
 * this intentionally reports a portable upper-bound proxy instead.
 */
final class FieldReadBenchmark
{
    /**
     * @return array{
     *   iterations: int,
     *   samples: int,
     *   fixtures: array<string, array{median_nanoseconds_per_operation: float, peak_memory_bytes: int, allocation_proxy_bytes: int, operations_per_sample: int}>,
     *   diagnostics: array{warm_public_to_matched_baseline: array{ratio: float, reference_ratio: float, baseline: string, candidate: string}, warm_protected_to_guarded_public: array{ratio: float, reference_ratio: float, baseline: string, candidate: string}}
     * }
     */
    public static function run(int $iterations = 100_000, int $samples = 7): array
    {
        if ($iterations < 1 || $samples < 3 || $samples % 2 === 0) {
            throw new \InvalidArgumentException('Field-read benchmarks require positive iterations and an odd sample count of at least three.');
        }

        $entity = new class (['id' => 1, 'title' => 'Tansi'], 'benchmark', ['id' => 'id', 'label' => 'title']) extends EntityBase {};
        $publicDefinition = new FieldDefinition('title', 'string', read: FieldReadLevel::Public);
        $resolver = new FieldReadMetadataResolver();
        $classType = EntityType::fromClass(BenchmarkFieldReadEntity::class);
        $classDefinition = $classType->getFieldDefinitions()['title'];
        $structure = new EntityStructure(
            entityTypeId: 'benchmark',
            bundleId: 'benchmark',
            id: 1,
            activeLanguageId: 'cr',
            defaultLanguageId: 'cr',
            knownTranslationIds: ['cr', 'en'],
            revisionId: 4,
            revisionTip: true,
            defaultRevision: true,
            fieldNames: ['id', 'title'],
        );
        $config = new class (['id' => 'site', 'title' => 'Site'], 'config_benchmark', ['id' => 'id', 'label' => 'title']) extends EntityBase {};
        $audit = new class (['id' => 9, 'title' => 'Recorded'], 'audit_benchmark', ['id' => 'id', 'label' => 'title']) extends EntityBase {};
        $principal = new AuthorizationPrincipal(7, true, ['member'], ['view protected benchmark fields'], 'claims-1');
        $scope = new AccountFieldReadScope();
        $protectedDefinition = new FieldDefinition('title', 'string', read: FieldReadLevel::Protected);
        $decision = static fn(AuthorizationPrincipalInterface $candidate, EntityStructure $candidateStructure, PolicySubjectViewInterface $subject, string $field): AccessResult =>
            $candidate->hasPermission('view protected benchmark fields') && $candidateStructure->hasField($field)
                ? AccessResult::allowed('benchmark fixture')
                : AccessResult::forbidden('benchmark fixture');
        $activeGuard = new FieldReadGuard($scope, $decision, activationEnabled: true);
        $publicRule = $resolver->compile($publicDefinition);
        $classPublicRule = $resolver->compile($classDefinition);
        $protectedRule = $resolver->compile($protectedDefinition);
        $publicEntity = new BenchmarkActivatedReadEntity(['id' => 1, 'title' => 'Tansi'], $activeGuard, ['title' => $publicRule]);
        $classPublicEntity = new BenchmarkActivatedReadEntity(['id' => 1, 'title' => 'Tansi'], $activeGuard, ['title' => $classPublicRule]);
        $protectedEntity = new BenchmarkActivatedReadEntity(['id' => 1, 'title' => 'Tansi'], $activeGuard, ['title' => $protectedRule]);
        [$auditedRead, $auditedCapability, $auditedBoundary, $auditedEntity] = self::auditedFixture();
        $auditedIterations = min($iterations, 1_000);
        $projectionIterations = min($iterations, 5_000);
        $wideValues = [];
        for ($field = 1; $field <= 50; ++$field) {
            $wideValues['field_' . $field] = $field;
        }
        $wide = new class ($wideValues, 'wide_benchmark', ['id' => 'id']) extends EntityBase {};

        $fixtures = [];
        $baselineOperation = static function () use ($entity, $iterations): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $entity->get('title');
            }
        };
        $fixtures['unbooted_public_baseline'] = self::measure($iterations, $samples, $baselineOperation);
        $fixtures['booted_class_definition_public'] = self::measure($iterations, $samples, static function () use ($classPublicEntity, $iterations): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $classPublicEntity->get('title');
            }
        });
        $guardedPublicOperation = static function () use ($publicEntity, $iterations): void {
            for ($i = 0; $i < $iterations; ++$i) {
                $publicEntity->get('title');
            }
        };
        $fixtures['booted_bundle_definition_public'] = self::measure($iterations, $samples, $guardedPublicOperation);
        $fixtures['translation_and_revision_public'] = self::measure($iterations, $samples, static function () use ($structure, $entity, $iterations): void {
            for ($i = 0; $i < $iterations; ++$i) {
                if (!$structure->revisionTip || $structure->activeLanguageId === '') {
                    throw new \LogicException('Translation/revision fixture is incomplete.');
                }
                $entity->get('title');
            }
        });
        $fixtures['config_and_audit_read_model_public'] = self::measure($iterations, $samples, static function () use ($config, $audit, $iterations): void {
            for ($i = 0; $i < $iterations; ++$i) {
                ($i % 2 === 0 ? $config : $audit)->get('title');
            }
        });
        $fixtures['principal_creation'] = self::measure($iterations, $samples, static function () use ($iterations): void {
            for ($i = 0; $i < $iterations; ++$i) {
                new AuthorizationPrincipal(7, true, ['member'], ['view protected benchmark fields'], 'claims-1');
            }
        });
        $fixtures['protected_cold'] = self::measure($iterations, $samples, static function () use ($scope, $principal, $activeGuard, $protectedEntity, $iterations): void {
            $scope->runWithGenerations($principal, 'class-1', 'policy-1', static function () use ($activeGuard, $protectedEntity, $iterations): void {
                for ($i = 0; $i < $iterations; ++$i) {
                    $activeGuard->invalidate($protectedEntity);
                    $protectedEntity->get('title');
                }
            });
        });
        $protectedWarmOperation = static function () use ($scope, $principal, $protectedEntity, $iterations): void {
            $scope->runWithGenerations($principal, 'class-1', 'policy-1', static function () use ($protectedEntity, $iterations): void {
                for ($i = 0; $i < $iterations; ++$i) {
                    $protectedEntity->get('title');
                }
            });
        };
        $fixtures['protected_warm'] = self::measure($iterations, $samples, $protectedWarmOperation);
        $fixtures['strict_audited_read'] = self::measure($auditedIterations, $samples, static function () use ($auditedRead, $auditedCapability, $auditedBoundary, $auditedEntity, $auditedIterations): void {
            for ($i = 0; $i < $auditedIterations; ++$i) {
                $auditedRead->read($auditedCapability, $auditedBoundary, $auditedEntity, 'mail');
            }
        });
        $fixtures['fifty_field_projection'] = self::measure($projectionIterations, $samples, static function () use ($wide, $projectionIterations): void {
            for ($i = 0; $i < $projectionIterations; ++$i) {
                for ($field = 1; $field <= 50; ++$field) {
                    $wide->get('field_' . $field);
                }
            }
        }, operationsPerIteration: 50);

        $publicEvidence = self::pairedRatioEvidence($baselineOperation, $guardedPublicOperation, $samples);
        $protectedEvidence = self::pairedRatioEvidence($guardedPublicOperation, $protectedWarmOperation, $samples);
        $publicRatio = $publicEvidence['median'];
        $protectedRatio = $protectedEvidence['median'];

        return [
            'iterations' => $iterations,
            'samples' => $samples,
            'fixtures' => $fixtures,
            'diagnostics' => [
                'warm_public_to_matched_baseline' => [
                    'ratio' => $publicRatio,
                    'minimum' => $publicEvidence['minimum'],
                    'maximum' => $publicEvidence['maximum'],
                    'paired_samples' => $publicEvidence['samples'],
                    'reference_ratio' => 1.25,
                    'baseline' => 'unbooted_public_baseline',
                    'candidate' => 'booted_bundle_definition_public',
                ],
                'warm_protected_to_guarded_public' => [
                    'ratio' => $protectedRatio,
                    'minimum' => $protectedEvidence['minimum'],
                    'maximum' => $protectedEvidence['maximum'],
                    'paired_samples' => $protectedEvidence['samples'],
                    'reference_ratio' => 2.0,
                    'baseline' => 'booted_bundle_definition_public',
                    'candidate' => 'protected_warm',
                ],
            ],
        ];
    }

    /**
     * @return array{median_nanoseconds_per_operation: float, peak_memory_bytes: int, allocation_proxy_bytes: int, operations_per_sample: int}
     */
    private static function measure(int $iterations, int $samples, \Closure $operation, int $operationsPerIteration = 1): array
    {
        $operation();
        $timings = [];
        $peak = 0;
        $allocationProxy = 0;
        for ($sample = 0; $sample < $samples; ++$sample) {
            if (function_exists('memory_reset_peak_usage')) {
                memory_reset_peak_usage();
            }
            $memoryBefore = memory_get_usage(true);
            $started = hrtime(true);
            $operation();
            $elapsed = hrtime(true) - $started;
            $samplePeak = memory_get_peak_usage(true);
            $timings[] = $elapsed / ($iterations * $operationsPerIteration);
            $peak = max($peak, $samplePeak);
            $allocationProxy = max($allocationProxy, $samplePeak - $memoryBefore);
        }
        sort($timings, SORT_NUMERIC);

        return [
            'median_nanoseconds_per_operation' => $timings[intdiv(count($timings), 2)],
            'peak_memory_bytes' => $peak,
            'allocation_proxy_bytes' => $allocationProxy,
            'operations_per_sample' => $iterations * $operationsPerIteration,
        ];
    }

    /** @return array{minimum: float, median: float, maximum: float, samples: list<float>} */
    private static function pairedRatioEvidence(\Closure $baseline, \Closure $candidate, int $samples): array
    {
        $baseline();
        $candidate();
        $ratios = [];
        for ($sample = 0; $sample < $samples; ++$sample) {
            if ($sample % 2 === 0) {
                $baselineStarted = hrtime(true);
                $baseline();
                $baselineElapsed = hrtime(true) - $baselineStarted;
                $candidateStarted = hrtime(true);
                $candidate();
                $candidateElapsed = hrtime(true) - $candidateStarted;
            } else {
                $candidateStarted = hrtime(true);
                $candidate();
                $candidateElapsed = hrtime(true) - $candidateStarted;
                $baselineStarted = hrtime(true);
                $baseline();
                $baselineElapsed = hrtime(true) - $baselineStarted;
            }
            $ratios[] = $candidateElapsed / $baselineElapsed;
        }
        sort($ratios, SORT_NUMERIC);

        return [
            'minimum' => $ratios[0],
            'median' => $ratios[intdiv(count($ratios), 2)],
            'maximum' => $ratios[array_key_last($ratios)],
            'samples' => $ratios,
        ];
    }

    /** @return array{AuditedFieldRead, object, object, EntityBase} */
    private static function auditedFixture(): array
    {
        $registry = new InMemoryCapabilityRegistry();
        $registry->register(new CapabilityDeclaration(
            issuer: 'benchmark.strict-read',
            reason: CapabilityReason::StrictAuditProjection,
            entityTypes: ['user'],
            bundles: ['user'],
            fields: ['mail'],
            actorSemantics: [CapabilityActorSemantics::System],
            justification: 'Field-read activation performance evidence.',
        ));
        $boundary = $registry->openBoundary('benchmark-boundary');
        $capability = $registry->issueValueRead('benchmark.strict-read', new CapabilityIssueContext(
            executionBoundary: 'benchmark-boundary',
            actorSemantics: CapabilityActorSemantics::System,
            actorId: 'benchmark-runner',
            tenantId: null,
            communityId: null,
            expiresAt: new \DateTimeImmutable('+2 minutes'),
            classificationGeneration: 'benchmark-classification',
            policyGeneration: 'benchmark-policy',
        ), $boundary);
        $ledger = new class implements StrictPrivilegedReadLedgerInterface {
            private int $receipt = 0;
            public function reserve(PrivilegedReadDescriptor $descriptor): PrivilegedReadReceipt
            {
                return new PrivilegedReadReceipt('benchmark-' . ++$this->receipt);
            }
            public function finalize(PrivilegedReadReceipt $receipt, PrivilegedReadOutcome $outcome): void {}
        };
        $entity = new class (['id' => 1, 'mail' => 'private@example.test'], 'user', ['id' => 'id']) extends EntityBase {};

        return [new AuditedFieldRead($registry, $ledger), $capability, $boundary, $entity];
    }
}

#[ContentEntityType(id: 'benchmark_field_read', label: 'Benchmark Field Read')]
#[ContentEntityKeys(id: 'id', label: 'title')]
final class BenchmarkFieldReadEntity extends ContentEntityBase
{
    #[Field(read: FieldReadLevel::Public)]
    public string $title = '';
}

/** Exact WP4 accessor fixture: cached rule lookup, guard, then current value/cast lookup. */
final class BenchmarkActivatedReadEntity extends EntityBase
{
    /** @param array<string, mixed> $values @param array<string, \Waaseyaa\Access\CompiledFieldReadRule> $rules */
    public function __construct(array $values, private readonly FieldReadGuard $guard, private readonly array $rules)
    {
        parent::__construct($values, 'benchmark', ['id' => 'id', 'label' => 'title']);
    }

    public function get(string $name): mixed
    {
        $rule = $this->rules[$name] ?? null;
        if ($rule !== null && $rule->level !== FieldReadLevel::Public) {
            $this->guard->assertCompiled($this, $rule);
        }

        return parent::get($name);
    }
}
