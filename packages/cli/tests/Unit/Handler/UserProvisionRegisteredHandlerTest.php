<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Handler;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\User\UserIdentityLookupInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\CLI\Command\HandlerCommand;
use Waaseyaa\CLI\Handler\UserProvisionRegisteredHandler;
use Waaseyaa\CLI\Provider\UserPermissionServiceProvider;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\CLI\UserProvisioning\RegisteredRoleAccountProvisioner;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\SiteContract\CanonicalJson;
use Waaseyaa\User\RegisteredRoleAssignmentService;
use Waaseyaa\User\Role;
use Waaseyaa\User\RoleRepository;
use Waaseyaa\User\User;

#[CoversClass(UserProvisionRegisteredHandler::class)]
final class UserProvisionRegisteredHandlerTest extends TestCase
{
    private const PASSWORD = 'sentinel-private-password';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
    }

    #[Test]
    public function commandHasNoSecretBearingArgumentsOrOptions(): void
    {
        $definition = $this->definition();

        self::assertSame([], $definition->handlerArguments());
        self::assertSame([], $definition->handlerOptions());
    }

    #[Test]
    public function providerBuildsProvisionerWithoutAutowiringItsPrivateClockSeam(): void
    {
        $roles = new RoleRepository([
            new Role('contributor', 'Contributor', ['create events']),
        ]);
        $identities = $this->createStub(UserIdentityLookupInterface::class);
        $internalFields = $this->createStub(UserInternalFieldReaderInterface::class);
        $services = [
            RoleRepository::class => $roles,
            UserIdentityLookupInterface::class => $identities,
            UserInternalFieldReaderInterface::class => $internalFields,
        ];
        $provider = new UserPermissionServiceProvider();
        $provider->setKernelServices(new readonly class ($services) implements KernelServicesInterface {
            /** @param array<class-string, object> $services */
            public function __construct(private array $services) {}

            public function get(string $abstract): ?object
            {
                return $this->services[$abstract] ?? null;
            }
        });
        $provider->register();

        self::assertInstanceOf(
            RegisteredRoleAccountProvisioner::class,
            $provider->resolve(RegisteredRoleAccountProvisioner::class),
        );
    }

    #[Test]
    public function canonicalStdinCreatesAccountAndEmitsOnlyClosedResult(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::once())->method('create')->willReturn(new User(['uid' => 41]));
        $repository->expects(self::once())->method('save')->willReturn(1);
        $path = $this->input(CanonicalJson::encode([
            'email' => 'owner@example.test',
            'password' => self::PASSWORD,
            'role' => 'contributor',
            'schema' => UserProvisionRegisteredHandler::INPUT_SCHEMA,
            'username' => 'community-owner',
            'version' => 1,
        ]) . "\n");

        $tester = $this->tester($repository, $path)->executeMap([]);

        self::assertSame(0, $tester->getExitCode());
        self::assertSame('', $tester->getStderr());
        self::assertSame(CanonicalJson::encode([
            'account_id' => '41',
            'code' => 'account_created',
            'role' => 'contributor',
            'schema' => 'waaseyaa.user-provision-registered-result.v1',
            'status' => 'created',
            'version' => 1,
        ]) . "\n", $tester->getStdout());
        self::assertStringNotContainsString(self::PASSWORD, $tester->getOutput());
    }

    #[Test]
    public function nonCanonicalOrOversizedInputIsRefusedBeforeStorage(): void
    {
        $repository = $this->createMock(EntityRepositoryInterface::class);
        $repository->expects(self::never())->method('create');
        $repository->expects(self::never())->method('save');
        $pretty = json_encode([
            'schema' => UserProvisionRegisteredHandler::INPUT_SCHEMA,
            'version' => 1,
            'username' => 'community-owner',
            'email' => 'owner@example.test',
            'role' => 'contributor',
            'password' => self::PASSWORD,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";

        $nonCanonical = $this->tester($repository, $this->input($pretty))->executeMap([]);
        $oversized = $this->tester(
            $repository,
            $this->input(str_repeat('x', UserProvisionRegisteredHandler::MAX_INPUT_BYTES + 1)),
        )->executeMap([]);

        self::assertSame(1, $nonCanonical->getExitCode());
        self::assertStringContainsString('"code":"invalid_input"', $nonCanonical->getStdout());
        self::assertStringNotContainsString(self::PASSWORD, $nonCanonical->getOutput());
        self::assertSame(1, $oversized->getExitCode());
        self::assertStringContainsString('"code":"invalid_input"', $oversized->getStdout());
    }

    private function definition(): HandlerCommand
    {
        foreach (new UserPermissionServiceProvider()->consoleCommands() as $command) {
            if ($command->name === 'user:provision-registered') {
                return $command;
            }
        }

        throw new \RuntimeException('Provisioning command definition not found.');
    }

    private function tester(EntityRepositoryInterface $repository, string $stdinPath): CliTester
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturn($repository);
        $identities = $this->createStub(UserIdentityLookupInterface::class);
        $identities->method('findActiveByLogin')->willReturn(null);
        $identities->method('findActiveByMail')->willReturn(null);
        $roles = new RoleRepository([
            new Role('contributor', 'Contributor', ['create events', 'edit own events']),
        ]);
        $handler = new UserProvisionRegisteredHandler(
            $manager,
            new RegisteredRoleAccountProvisioner(
                new RegisteredRoleAssignmentService($roles),
                $identities,
                $this->createStub(UserInternalFieldReaderInterface::class),
                static fn(): int => 1_800_000_000,
            ),
            $stdinPath,
        );
        $container = new class ($handler) implements ContainerInterface {
            public function __construct(private readonly UserProvisionRegisteredHandler $handler) {}
            public function get(string $id): mixed
            {
                if ($id === UserProvisionRegisteredHandler::class) {
                    return $this->handler;
                }
                throw new \RuntimeException('Unexpected service.');
            }
            public function has(string $id): bool
            {
                return $id === UserProvisionRegisteredHandler::class;
            }
        };

        return CliTester::for($this->definition(), $container);
    }

    private function input(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'waaseyaa-provision-input-');
        self::assertIsString($path);
        self::assertSame(strlen($contents), file_put_contents($path, $contents));
        $this->paths[] = $path;

        return $path;
    }
}
