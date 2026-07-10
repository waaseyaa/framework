<?php

declare(strict_types=1);

namespace Waaseyaa\Migration\Exception;

/**
 * Thrown by {@see \Waaseyaa\Migration\ContentModel\ContentModelRegistrar}
 * when it cannot register a derived content type or its fields.
 *
 * G-026 (#1940) failure-semantics decision: the registrar used to swallow
 * every failure behind a `notice`/`error` log line and continue, because it
 * was (on paper) invocable during `AbstractKernel::boot()`'s schema-sync
 * phase, where a hard failure would have crashed kernel boot for reasons
 * unrelated to the content model itself. Now that the registrar is invoked
 * only from {@see \Waaseyaa\Migration\Runner\MigrationRunner}, at the first
 * `import:*` command — after the whole kernel, including the destination
 * schema, is live — a registration failure is a genuine, actionable error
 * (bad model, unregistered destination entity type, field registry
 * misconfiguration) and should abort the import loudly, before any source
 * record is read, rather than degrade to "some content silently landed in
 * the `_data` blob instead of a typed column."
 *
 * The only paths that remain silent are genuine already-registered /
 * not-applicable cases, which are not failures: a bundle that already has
 * its config entity (idempotent re-run) and an entity type that declares no
 * `bundleEntityType` (bundle is a bare string; there is nothing to
 * materialize).
 *
 * @api
 */
final class ContentModelRegistrationException extends \RuntimeException {}
