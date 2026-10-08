<?php

declare(strict_types=1);

namespace Waaseyaa\Listing;

use InvalidArgumentException;
use Waaseyaa\Entity\EntityTypeInterface;

/**
 * Declarative listing definition (the "View" in Views-equivalent semantics).
 *
 * Shallow construction-time invariants are enforced here; cross-field /
 * boot-time invariants (entity-type existence, field/backend support,
 * page-size cap, langcode-on-translatable) are deferred to
 * {@code ListingDefinitionValidator} (WP10).
 *
 * @api
 */
final readonly class ListingDefinition
{
    private const ID_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const DEFAULT_ACCESS_OPS = ['view'];

    /**
     * Constructor parameter types are intentionally widened to plain
     * scalars/arrays so that the runtime invariants in
     * {@see self::validateShallow()} are exercised by callers (and by the
     * negative-path unit tests). The narrower property-promoted shape
     * — `non-empty-string $id`, `positive-int $pageSize`, etc. — is
     * documented on the public properties via the data-model contract.
     *
     * @param list<mixed> $filters Each entry validated to be a {@see FilterDefinition}.
     * @param list<mixed> $sorts   Each entry validated to be a {@see SortDefinition}.
     * @param list<mixed> $accessOps Each entry validated to be a non-empty string.
     */
    public function __construct(
        public string $id,
        public string $entityType,
        public ?string $bundle = null,
        public array $filters = [],
        public array $sorts = [],
        public ?int $pageSize = 20,
        public array $accessOps = self::DEFAULT_ACCESS_OPS,
        public bool $approximateTotal = false,
        public ?int $cacheTtl = null,
        private bool $unbounded = false,
    ) {
        $this->validateShallow();
    }

    /**
     * Fluent builder: opt out of the page-size cap (FR-051 / R-01).
     *
     * Returns a clone with {@code $unbounded = true}; all other params unchanged.
     */
    public function allowUnbounded(): self
    {
        return new self(
            id: $this->id,
            entityType: $this->entityType,
            bundle: $this->bundle,
            filters: $this->filters,
            sorts: $this->sorts,
            pageSize: $this->pageSize,
            accessOps: $this->accessOps,
            approximateTotal: $this->approximateTotal,
            cacheTtl: $this->cacheTtl,
            unbounded: true,
        );
    }

    public function isUnbounded(): bool
    {
        return $this->unbounded;
    }

    /**
     * Effective cache contexts — declared (via exposedParam filters) plus
     * implicit per FR-024:
     * - `url.query.page` when `$pageSize !== null`
     * - `url.query.<param>` per exposedParam
     * - `language.content` when entity type is translatable
     * - `user.roles` when `$accessOps` differs from the default {'view'}
     *
     * Order: implicit-first then exposed-params, sorted by name within
     * each bucket for deterministic cache-key derivation.
     *
     * @return list<non-empty-string>
     */
    public function effectiveContexts(EntityTypeInterface $entityType): array
    {
        $contexts = [];

        if ($this->pageSize !== null) {
            $contexts[] = 'url.query.page';
        }

        if ($entityType->isTranslatable()) {
            $contexts[] = 'language.content';
        }

        if ($this->accessOps !== self::DEFAULT_ACCESS_OPS) {
            $contexts[] = 'user.roles';
        }

        $exposedParams = [];
        foreach ($this->filters as $filter) {
            if ($filter->exposedParam !== null) {
                $exposedParams[] = 'url.query.' . $filter->exposedParam;
            }
        }
        sort($exposedParams);

        return array_values(array_unique([...$contexts, ...$exposedParams]));
    }

    /**
     * Deterministic 16-hex-char cache-key suffix derived from the
     * canonical JSON of the definition's stable surface (FR-005 / FR-037).
     *
     * ListingHash preserves this package's numeric-key ordering and float
     * identity. Other framework canonical encoders have distinct contracts.
     */
    public function cacheKeyHash(): string
    {
        return ListingHash::of($this->toHashable());
    }

    /**
     * @return array<string, mixed>
     */
    private function toHashable(): array
    {
        return [
            'id' => $this->id,
            'entityType' => $this->entityType,
            'bundle' => $this->bundle,
            'filters' => array_map(
                static fn(FilterDefinition $f): array => [
                    'field' => $f->field,
                    'op' => $f->op->value,
                    'value' => $f->value,
                    'exposedParam' => $f->exposedParam,
                ],
                $this->filters,
            ),
            'sorts' => array_map(
                static fn(SortDefinition $s): array => [
                    'field' => $s->field,
                    'direction' => $s->direction->value,
                ],
                $this->sorts,
            ),
            'pageSize' => $this->pageSize,
            'accessOps' => $this->accessOps,
            'approximateTotal' => $this->approximateTotal,
            'cacheTtl' => $this->cacheTtl,
            'unbounded' => $this->unbounded,
        ];
    }

    private function validateShallow(): void
    {
        if (preg_match(self::ID_PATTERN, $this->id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'ListingDefinition: $id "%s" must match %s.',
                $this->id,
                self::ID_PATTERN,
            ));
        }
        if ($this->entityType === '') {
            throw new InvalidArgumentException('ListingDefinition: $entityType must be non-empty.');
        }
        if ($this->bundle !== null && $this->bundle === '') {
            throw new InvalidArgumentException('ListingDefinition: $bundle must be non-empty when set.');
        }
        if ($this->accessOps === []) {
            throw new InvalidArgumentException('ListingDefinition: $accessOps must be non-empty.');
        }
        foreach ($this->accessOps as $op) {
            if (!is_string($op) || $op === '') {
                throw new InvalidArgumentException('ListingDefinition: every $accessOps entry must be a non-empty string.');
            }
        }
        if ($this->pageSize !== null && $this->pageSize < 1) {
            throw new InvalidArgumentException('ListingDefinition: $pageSize must be a positive integer or null.');
        }
        if ($this->cacheTtl !== null && $this->cacheTtl < 1) {
            throw new InvalidArgumentException('ListingDefinition: $cacheTtl must be a positive integer or null.');
        }
        foreach ($this->filters as $i => $filter) {
            if (!$filter instanceof FilterDefinition) {
                throw new InvalidArgumentException(sprintf(
                    'ListingDefinition: $filters[%s] must be a FilterDefinition, got %s.',
                    (string) $i,
                    get_debug_type($filter),
                ));
            }
        }
        foreach ($this->sorts as $i => $sort) {
            if (!$sort instanceof SortDefinition) {
                throw new InvalidArgumentException(sprintf(
                    'ListingDefinition: $sorts[%s] must be a SortDefinition, got %s.',
                    (string) $i,
                    get_debug_type($sort),
                ));
            }
        }
    }
}
