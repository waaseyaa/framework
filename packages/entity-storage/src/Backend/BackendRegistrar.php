<?php

declare(strict_types=1);

namespace Waaseyaa\EntityStorage\Backend;

use Waaseyaa\EntityStorage\Exception\BackendIdCollisionException;

/**
 * @api
 *
 * Discovers and indexes all registered {@see FieldStorageBackendInterface} instances.
 *
 * Discovery uses the Composer-resolved provider list (passed in as a list of
 * FQCNs). Providers implementing {@see HasFieldStorageBackendsInterface} are
 * instantiated and their backends are indexed by {@see FieldStorageBackendInterface::id()}.
 *
 * Registration order: Composer installed.json order by default. Providers may
 * declare a `public const BACKEND_PRIORITY = int` constant to influence
 * tie-breaking (higher integer wins first position). This does not affect
 * duplicate-id detection — collisions always raise an exception.
 *
 * Reserved id enforcement: only this class (framework internals) may register
 * backends under the reserved ids (sql-blob, sql-column, vector). Third-party
 * providers that attempt to do so get a {@see BackendIdCollisionException}.
 *
 * @internal Construct via {@see \Waaseyaa\EntityStorage\EntityStorageFactory} or the service provider.
 */
final class BackendRegistrar
{
    /** @internal String FQN avoids an upward layer import from entity-storage (L1) into foundation (L0). */
    private const CAPABILITY_INTERFACE = 'Waaseyaa\\EntityStorage\\Backend\\HasFieldStorageBackendsInterface';
    private const CAPABILITY_V2_INTERFACE = 'Waaseyaa\\EntityStorage\\Backend\\HasFieldStorageBackendsV2Interface';

    /** @var array<string, FieldStorageBackendInterface> */
    private array $backends = [];

    /** @var array<string, string> id => provider FQCN that registered it */
    private array $registeredBy = [];

    /** @var array<string, FieldStorageBackendGateway> */
    private array $gateways = [];

    /** @var array<string, string> */
    private array $gatewayFingerprints = [];

    /** @var array<string, string> id => provider */
    private array $v2RegisteredBy = [];

    /** @var list<string> */
    private array $v1BackendBlockers = [];

    /**
     * @param string[] $providerFqcns Ordered list of service-provider class names (Composer installed.json order).
     * @param string[] $frameworkProviderFqcns FQCNs that are allowed to register reserved ids.
     */
    public function __construct(
        private readonly array $providerFqcns,
        private readonly array $frameworkProviderFqcns = [],
        private readonly ?StrictFieldStorageGatewayAuditInterface $gatewayAudit = null,
    ) {}

    /**
     * Discover backends from all providers and build the index.
     *
     * Safe to call multiple times; each call rebuilds the index from scratch.
     *
     * @throws BackendIdCollisionException on duplicate or reserved-id misuse.
     */
    public function build(): void
    {
        $this->buildInternal(activateGateways: true);
    }

    /**
     * Discover and validate exact V1/V2 identities without activating a gateway.
     * Used only by dormant WP3 preflight; WP4 activation requires build().
     */
    public function buildPreflightInventory(): void
    {
        $this->buildInternal(activateGateways: false);
    }

    private function buildInternal(bool $activateGateways): void
    {
        $this->backends = [];
        $this->registeredBy = [];
        $this->gateways = [];
        $this->gatewayFingerprints = [];
        $this->v2RegisteredBy = [];
        $this->v1BackendBlockers = [];

        $sorted = $this->sortByPriority($this->providerFqcns);

        foreach ($sorted as $providerFqcn) {
            if (!class_exists($providerFqcn)) {
                continue;
            }

            $implements = class_implements($providerFqcn);
            if (!is_array($implements)) {
                continue;
            }

            $provider = new $providerFqcn();
            if (isset($implements[self::CAPABILITY_INTERFACE])) {
                $backends = $provider->fieldStorageBackends();
                foreach ($backends as $backend) {
                    $this->register($backend, $providerFqcn);
                    $this->v1BackendBlockers[] = $providerFqcn . ':' . $backend->id() . ':' . $backend::class;
                }
            }
            if (isset($implements[self::CAPABILITY_V2_INTERFACE])) {
                if ($activateGateways && $this->gatewayAudit === null) {
                    throw new \LogicException('V2 field-storage backend registration requires a strict gateway audit.');
                }
                foreach ($provider->fieldStorageBackendsV2() as $backend) {
                    $this->registerV2($backend, $providerFqcn, $this->gatewayAudit, $activateGateways);
                }
            }
        }

        sort($this->v1BackendBlockers);
    }

    /**
     * Return a backend by id, or null if not registered.
     */
    public function get(string $id): ?FieldStorageBackendInterface
    {
        return $this->backends[$id] ?? null;
    }

    /** Return the registrar-owned V2 facade; raw V2 implementations are never exposed. */
    public function gateway(string $id): ?FieldStorageBackendGateway
    {
        return $this->gateways[$id] ?? null;
    }

    /** @return array<string, string> backend id => implementation class:fingerprint */
    public function gatewayFingerprints(): array
    {
        $inventory = $this->gatewayFingerprints;
        ksort($inventory);

        return $inventory;
    }

    /** @return list<string> exact provider:id:implementation blockers for WP4 V1 removal */
    public function v1BackendBlockers(): array
    {
        return $this->v1BackendBlockers;
    }

    /**
     * Return all registered backends, keyed by id.
     *
     * @return array<string, FieldStorageBackendInterface>
     */
    public function all(): array
    {
        return $this->backends;
    }

    /**
     * Return whether a backend id is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->backends[$id]);
    }

    /**
     * Validate that all storedIn() backend ids on field definitions are registered.
     *
     * @param string[] $backendIds
     * @throws \InvalidArgumentException when an unknown id is found.
     */
    public function validateFieldBackendIds(array $backendIds): void
    {
        foreach ($backendIds as $id) {
            if (!$this->has($id)) {
                throw new \InvalidArgumentException(
                    sprintf(
                        'Field references unknown backend id "%s". '
                        . 'Registered backends: [%s].',
                        $id,
                        implode(', ', array_keys($this->backends)),
                    ),
                );
            }
        }
    }

    private function register(FieldStorageBackendInterface $backend, string $providerFqcn): void
    {
        $id = $backend->id();
        $isReserved = in_array($id, ReservedBackendIds::all(), true);
        $isFramework = in_array($providerFqcn, $this->frameworkProviderFqcns, true);

        if ($isReserved && !$isFramework) {
            // Third-party provider trying to claim a reserved id.
            // Pass null for $firstFqcn — the reserved-id message path in
            // BackendIdCollisionException produces the clearer operator message.
            throw new BackendIdCollisionException(
                $id,
                null,
                $providerFqcn,
            );
        }

        if (isset($this->registeredBy[$id])) {
            throw new BackendIdCollisionException(
                $id,
                $this->registeredBy[$id],
                $providerFqcn,
            );
        }

        $this->backends[$id] = $backend;
        $this->registeredBy[$id] = $providerFqcn;
    }

    private function registerV2(
        FieldStorageBackendV2Interface $backend,
        string $providerFqcn,
        ?StrictFieldStorageGatewayAuditInterface $audit,
        bool $activateGateway,
    ): void {
        $id = $backend->id();
        $fingerprint = $backend->fingerprint();
        if (preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'V2 field-storage backend "%s" fingerprint must be 64 lowercase hexadecimal characters.',
                $id,
            ));
        }
        $isReserved = in_array($id, ReservedBackendIds::all(), true);
        $isFramework = in_array($providerFqcn, $this->frameworkProviderFqcns, true);
        if ($isReserved && !$isFramework) {
            throw new BackendIdCollisionException($id, null, $providerFqcn);
        }
        if (isset($this->v2RegisteredBy[$id])) {
            throw new BackendIdCollisionException($id, $this->v2RegisteredBy[$id], $providerFqcn);
        }
        if (in_array($backend::class . ':' . $fingerprint, $this->gatewayFingerprints, true)) {
            throw new \InvalidArgumentException(sprintf('V2 field-storage fingerprint is already registered: %s', $fingerprint));
        }

        if ($activateGateway) {
            if ($audit === null) {
                throw new \LogicException('V2 field-storage backend activation requires a strict gateway audit.');
            }
            $this->gateways[$id] = new FieldStorageBackendGateway($id, $backend, $fingerprint, $audit);
        }
        $this->v2RegisteredBy[$id] = $providerFqcn;
        $this->gatewayFingerprints[$id] = $backend::class . ':' . $fingerprint;
    }

    /**
     * Sort providers by their optional BACKEND_PRIORITY constant (descending).
     * Providers without the constant get priority 0.
     *
     * @param string[] $fqcns
     * @return string[]
     */
    private function sortByPriority(array $fqcns): array
    {
        usort($fqcns, static function (string $a, string $b): int {
            $pa = defined("{$a}::BACKEND_PRIORITY") ? constant("{$a}::BACKEND_PRIORITY") : 0;
            $pb = defined("{$b}::BACKEND_PRIORITY") ? constant("{$b}::BACKEND_PRIORITY") : 0;

            if ($pa === $pb) {
                return 0;
            }

            return $pa > $pb ? -1 : 1;
        });

        return $fqcns;
    }
}
