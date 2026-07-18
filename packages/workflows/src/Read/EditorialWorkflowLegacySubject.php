<?php

declare(strict_types=1);

namespace Waaseyaa\Workflows\Read;

/** Exact immutable view retained only for the legacy editorial service. @internal */
final readonly class EditorialWorkflowLegacySubject
{
    /** @param list<array<string, mixed>> $audit */
    public function __construct(
        public ?string $workflowState,
        public int $status,
        public array $audit,
    ) {}
}
