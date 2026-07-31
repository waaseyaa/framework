<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/**
 * Registers the framework's two built-in field-storage backends (#2160).
 *
 * ## Why this class did not exist, and what broke
 *
 * `SqlBlobBackend` and `SqlColumnBackend` both instruct callers to "construct
 * via the framework provider" — but no such provider was ever written, and
 * nothing else implements {@see HasFieldStorageBackendsV2Interface} outside test
 * fixtures. `BackendRegistrarFactory` keeps only classes implementing that
 * interface, so every real application booted with an **empty** registrar.
 *
 * That stayed invisible because `DefinitionValidator::validateType()` skips
 * every field that is not `isIndexed()`, and `BackendResolver::resolve()` — the
 * registrar's only live consumer — is reached only for indexed fields. Until
 * #2157 made `indexed: true` declarable from `#[Field]`, no shipped entity type
 * had one, so the lookup never happened. The first application to declare an
 * indexed field got `UnknownBackendException: Backend id "sql-blob" is not
 * registered` at boot — on the *default* backend, which is what showed the
 * failure had nothing to do with the backend id.
 *
 * ## Reserved ids
 *
 * Implements {@see IsFrameworkBackendProviderV2Interface}, without which
 * `BackendRegistrar::registerV2()` rejects `sql-blob` and `sql-column` as
 * reserved ids claimed by a third party.
 *
 * ## Construction
 *
 * `BackendRegistrar` instantiates providers with `new $fqcn()`, so this class
 * takes no constructor arguments and cannot be handed a database. It extends
 * {@see ServiceProvider} because `extra.waaseyaa.providers` feeds both the
 * backend registrar and `ProviderRegistry`, which warns on every boot about any
 * declared class that is not one; the capability interface is documented as a
 * *provider capability* and is designed to be carried by an ordinary service
 * provider. `register()` is intentionally empty — this provider contributes
 * backends, not container bindings. The backends
 * it returns are therefore built with {@see SqlBlobBackend::forQuerySupport()} /
 * {@see SqlColumnBackend::forQuerySupport()}: registry-global instances that
 * answer `supportsQuery()` — the only operation the registrar path performs —
 * and throw on read/write/delete rather than silently targeting the wrong
 * table. Per-entity-type instances are still constructed directly, with a real
 * database, by the storage layer that knows the table.
 *
 * @api
 */
final class FrameworkFieldStorageBackendProvider extends ServiceProvider implements IsFrameworkBackendProviderV2Interface
{
    /**
     * Registered ahead of any third-party provider, so a reserved-id collision
     * is reported against the third party rather than depending on scan order.
     */
    public const int BACKEND_PRIORITY = 1000;

    /**
     * No container bindings: this provider exists to contribute backends.
     */
    public function register(): void {}

    /** @return list<FieldStorageBackendV2Interface> */
    public function fieldStorageBackendsV2(): array
    {
        return [
            SqlBlobBackend::forQuerySupport(),
            SqlColumnBackend::forQuerySupport(),
        ];
    }
}
