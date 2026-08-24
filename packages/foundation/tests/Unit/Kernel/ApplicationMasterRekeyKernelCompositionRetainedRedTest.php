<?php

declare(strict_types=1);

namespace Waaseyaa\Foundation\Tests\Unit\Kernel;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterBatchResult;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterInventorySnapshot;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyAdapterInterface;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContext;
use Waaseyaa\Foundation\Security\Rekey\ApplicationMasterRekeyContribution;
use Waaseyaa\Foundation\ServiceProvider\Capability\ProvidesApplicationMasterRekeyContributionsInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

/** Retained-red proof that the kernel freezes the complete active owner graph before provider boot. */
final class ApplicationMasterRekeyKernelCompositionRetainedRedTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_rekey_kernel_' . uniqid();
        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);
        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
        file_put_contents(
            $this->projectRoot . '/config/entity-types.php',
            "<?php\nreturn [new \\Waaseyaa\\Entity\\EntityType("
                . "id: 'test', label: 'Test', class: \\stdClass::class, keys: ['id' => 'id'])];\n",
        );
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->projectRoot);
    }

    #[Test]
    public function full_boot_composes_the_complete_provider_graph_before_any_provider_boots(): void
    {
        $kernel = $this->kernelWithProviders([KernelRekeyContributionProvider::class]);

        $kernel->publicBoot();

        $composition = $kernel->getApplicationMasterRekeyComposition();
        self::assertNotNull($composition);
        self::assertSame(
            [ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC],
            $composition->purposes()->purposeIds(),
        );
        self::assertSame($kernel->getDatabase(), $composition->adapters()[0]->databaseAuthority());
        $provider = $kernel->getProviders()[0];
        self::assertInstanceOf(KernelRekeyContributionProvider::class, $provider);
        self::assertTrue($provider->contributionRequested);
        self::assertTrue($provider->bootedAfterContribution);
    }

    #[Test]
    public function an_install_without_active_contributors_exposes_no_registry(): void
    {
        $kernel = $this->kernelWithProviders([]);

        $kernel->publicBoot();

        self::assertNull($kernel->getApplicationMasterRekeyComposition());
    }

    #[Test]
    public function restricted_discovery_executes_neither_contributions_nor_provider_boot(): void
    {
        $kernel = $this->kernelWithProviders([KernelRekeyContributionProvider::class]);

        $kernel->publicRestrictedBoot();

        self::assertNull($kernel->getApplicationMasterRekeyComposition());
        $provider = $kernel->getProviders()[0];
        self::assertInstanceOf(KernelRekeyContributionProvider::class, $provider);
        self::assertFalse($provider->contributionRequested);
        self::assertFalse($provider->bootedAfterContribution);
    }

    #[Test]
    public function a_later_boot_failure_exposes_no_successfully_composed_partial_graph(): void
    {
        $kernel = $this->kernelWithProviders([KernelRekeyContributionProvider::class], failAfterProviderBoot: true);

        try {
            $kernel->publicBoot();
            self::fail('The synthetic post-provider boot failure must escape.');
        } catch (\RuntimeException $exception) {
            self::assertSame('synthetic-late-boot-failure', $exception->getMessage());
        }

        self::assertNull($kernel->getApplicationMasterRekeyComposition());
    }

    /**
     * @param list<class-string<ServiceProvider>> $providers
     */
    private function kernelWithProviders(array $providers, bool $failAfterProviderBoot = false): AbstractKernel
    {
        return new class ($this->projectRoot, $providers, $failAfterProviderBoot) extends AbstractKernel {
            /** @param list<class-string<ServiceProvider>> $providerClasses */
            public function __construct(
                string $projectRoot,
                private readonly array $providerClasses,
                private readonly bool $failAfterProviderBoot,
            ) {
                parent::__construct($projectRoot);
            }

            public function publicBoot(): void
            {
                $this->boot();
            }

            public function publicRestrictedBoot(): void
            {
                $this->bootForFieldAccessPreflight();
            }

            protected function compileManifest(): void
            {
                $this->manifest = new PackageManifest(providers: $this->providerClasses);
            }

            protected function bootProviders(): void
            {
                parent::bootProviders();
                if ($this->failAfterProviderBoot) {
                    throw new \RuntimeException('synthetic-late-boot-failure');
                }
            }
        };
    }
}

final class KernelRekeyContributionProvider extends ServiceProvider implements ProvidesApplicationMasterRekeyContributionsInterface
{
    public bool $contributionRequested = false;
    public bool $bootedAfterContribution = false;

    public function register(): void {}

    public function boot(): void
    {
        if (!$this->contributionRequested) {
            throw new \LogicException('Application-master owners must compose before provider boot.');
        }
        $this->bootedAfterContribution = true;
    }

    public function applicationMasterRekeyContributions(): iterable
    {
        $this->contributionRequested = true;
        $database = $this->resolve(DatabaseInterface::class);
        if (!$database instanceof DatabaseInterface) {
            throw new \LogicException('Synthetic provider requires the kernel database authority.');
        }

        yield new ApplicationMasterRekeyContribution(
            new KernelCompositionRekeyAdapter($database),
            [new ApplicationMasterPurposePolicy(
                id: ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC,
                ownerPackage: 'waaseyaa/audit',
                strategy: ApplicationMasterPurposeStrategy::RetainHistoricVerifier,
                maximumLifetimeSeconds: 0,
                retentionSeconds: 86_400,
                adapterId: 'kernel-audit-checkpoint-v1',
                rollbackBehavior: 'retain-historic-verifier',
            )],
        );
    }
}

final readonly class KernelCompositionRekeyAdapter implements ApplicationMasterRekeyAdapterInterface
{
    public function __construct(private DatabaseInterface $database) {}

    public function databaseAuthority(): DatabaseInterface
    {
        return $this->database;
    }

    public function id(): string
    {
        return 'kernel-audit-checkpoint-v1';
    }

    public function purposeIds(): array
    {
        return [ApplicationSecret::PURPOSE_AUDIT_CHECKPOINT_HMAC];
    }

    public function snapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return new ApplicationMasterInventorySnapshot(hash('sha256', 'kernel-composition-snapshot'), 0);
    }

    public function transitionBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new \LogicException('Synthetic kernel composition adapters never execute.');
    }

    public function verify(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        return [];
    }

    public function rollbackBatch(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
        ?string $cursor,
        int $limit,
    ): ApplicationMasterBatchResult {
        throw new \LogicException('Synthetic kernel composition adapters never execute.');
    }

    public function rollbackSnapshot(ApplicationMasterRekeyContext $context): ApplicationMasterInventorySnapshot
    {
        return new ApplicationMasterInventorySnapshot(hash('sha256', 'kernel-composition-rollback'), 0);
    }

    public function verifyRollback(
        ApplicationMasterRekeyContext $context,
        ApplicationMasterInventorySnapshot $snapshot,
    ): array {
        return [];
    }
}
