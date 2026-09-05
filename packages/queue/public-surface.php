<?php

declare(strict_types=1);

// Migrated by bin/migrate-surface-map from docs/public-surface-map.php
// and docs/public-surface-map.md (FW-DELIVERY-SURFACE-01 / #2901). This
// file, not the generated docs/public-surface-map.*, is the editable
// authority — see docs/specs/public-surface-declarations.md.
return [
    'entries' => [
        ['fqcn' => 'Waaseyaa\\Queue\\Envelope\\NoAuthorityQueueRuntime', 'disposition' => 'public', 'purpose' => 'Dormant runtime that installs no account or capability authority'],
        ['fqcn' => 'Waaseyaa\\Queue\\Envelope\\QueueAuthorityRuntimeInterface', 'disposition' => 'public', 'purpose' => 'Confines envelope authority resolution and installation to one handler invocation'],
        ['fqcn' => 'Waaseyaa\\Queue\\Envelope\\QueueAuthorityScopeInterface', 'disposition' => 'public', 'purpose' => 'Closeable handler-only authority installation used by queue runtimes'],
        ['fqcn' => 'Waaseyaa\\Queue\\Envelope\\QueueEnvelopeFactoryInterface', 'disposition' => 'public', 'purpose' => 'Dispatch-bound factory for an explicit actor/system authority envelope'],
        ['fqcn' => 'Waaseyaa\\Queue\\Envelope\\QueueEnvelopeV1', 'disposition' => 'public', 'purpose' => 'Dormant versioned authority envelope carrying exactly one actor or system authority plus tenant/community and correlation dimensions'],
        ['fqcn' => 'Waaseyaa\\Queue\\Envelope\\QueueSystemReason', 'disposition' => 'public', 'purpose' => 'Closed reason vocabulary for system-owned queue authority'],
        ['fqcn' => 'Waaseyaa\\Queue\\Envelope\\ScopedQueueAuthorityRuntime', 'disposition' => 'public', 'purpose' => 'Resolves and closes one handler-only authority scope in `finally`'],
        ['fqcn' => 'Waaseyaa\\Queue\\Envelope\\SystemQueueEnvelopeFactory', 'disposition' => 'public', 'purpose' => 'Explicit reviewed system-authority envelope factory; never the generic dispatch default'],
        ['fqcn' => 'Waaseyaa\\Queue\\Exception\\InvalidPersistentPayload', 'disposition' => 'public', 'purpose' => 'Exact replay payload failed authentication'],
        ['fqcn' => 'Waaseyaa\\Queue\\FailedJobRepositoryInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Queue\\Handler\\HandlerInterface', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Queue\\Job', 'disposition' => 'internal'],
        ['fqcn' => 'Waaseyaa\\Queue\\OccurrenceQueueInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Queue\\Occurrence\\OccurrenceAwareMessageInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Queue\\Occurrence\\OccurrenceContextInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Queue\\Occurrence\\OccurrenceRunResult', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Queue\\Occurrence\\OccurrenceRuntimeInterface', 'disposition' => 'public'],
        ['fqcn' => 'Waaseyaa\\Queue\\PersistentPayloadReplayInterface', 'disposition' => 'public', 'purpose' => 'Replays the exact authenticated persistent payload without replacing its envelope metadata'],
        ['fqcn' => 'Waaseyaa\\Queue\\PersistentQueueBoundaryConfig', 'disposition' => 'public', 'purpose' => 'Explicit dormant/enforced persistent dispatch and legacy-envelope mode'],
        ['fqcn' => 'Waaseyaa\\Queue\\QueueInterface', 'disposition' => 'public', 'purpose' => 'Dispatches messages to the queue for asynchronous processing'],
        ['fqcn' => 'Waaseyaa\\Queue\\QueuePayloadDeprecationDiagnostic', 'disposition' => 'public', 'purpose' => 'Bounded nested entity-payload diagnostic for persistent dispatch'],
        ['fqcn' => 'Waaseyaa\\Queue\\Transport\\TransportInterface', 'disposition' => 'internal'],
    ],
];
