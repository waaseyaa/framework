<?php

declare(strict_types=1);

namespace Waaseyaa\CLI\Tests\Unit\Command\Make;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\CLI\Handler\MakePolicyHandler;
use Waaseyaa\CLI\Provider\MakeServiceProviderA;
use Waaseyaa\CLI\Testing\CliTester;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Testing\Factory\AuthorizationPrincipalFactory;

/**
 * Minimal named {@see EntityInterface} stub so the generated policy's
 * `assert($entity instanceof \<FQCN>)` (rendered from `--entity-class`) has
 * a real, nameable class to assert against — an anonymous class has no
 * stable FQCN a CLI option could name.
 */
final class MakePolicyCommandTestStubEntity implements EntityInterface
{
    public function id(): int|string|null
    {
        return 1;
    }

    public function uuid(): string
    {
        return 'stub-uuid';
    }

    public function label(): string
    {
        return 'Stub';
    }

    public function getEntityTypeId(): string
    {
        return 'article';
    }

    public function bundle(): string
    {
        return 'article';
    }

    public function isNew(): bool
    {
        return false;
    }

    public function get(string $name): mixed
    {
        return null;
    }

    public function set(string $name, mixed $value): static
    {
        return $this;
    }

    public function toArray(): array
    {
        return [];
    }

    public function language(): string
    {
        return 'en';
    }
}

/**
 * #2848: `make:policy` renders through the same
 * `AccessPolicyEmitter::renderPolicyClass()` the blueprint compiler uses, so
 * these assertions describe the real `AccessPolicyInterface` contract
 * (`access()`/`createAccess()`/`appliesTo()`), not the pre-#2848 stub's
 * `view()`/`create()`/`update()`/`delete()` shape, which never satisfied the
 * interface it claimed to implement.
 */
#[CoversClass(MakePolicyHandler::class)]
final class MakePolicyCommandTest extends TestCase
{
    #[Test]
    public function it_generates_a_policy_class_bound_to_the_declared_entity_and_grants(): void
    {
        $tester = $this->createTester();
        $tester->execute(['ContentPolicy', '--entity=article', '--grant=view:view article', '--grant=update:edit article']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringContainsString('declare(strict_types=1);', $output);
        $this->assertStringContainsString("#[PolicyAttribute(entityType: 'article')]", $output);
        $this->assertStringContainsString('final class ContentPolicy implements AccessPolicyInterface', $output);
        $this->assertStringContainsString('use Waaseyaa\\Access\\AccessPolicyInterface;', $output);
        $this->assertStringContainsString('public function appliesTo(', $output);
        $this->assertStringContainsString('public function access(', $output);
        $this->assertStringContainsString('public function createAccess(', $output);
        $this->assertStringContainsString("\$account->hasPermission('view article')", $output);
        $this->assertStringContainsString("\$account->hasPermission('edit article')", $output);
    }

    #[Test]
    public function it_defaults_to_an_all_neutral_default_deny_policy_when_no_grants_are_supplied(): void
    {
        $tester = $this->createTester();
        $tester->execute(['ContentPolicy', '--entity=article']);

        $this->assertSame(0, $tester->getExitCode());
        $output = $tester->getStdout();
        $this->assertStringNotContainsString('return AccessResult::allowed', $output);
        $this->assertStringNotContainsString('return AccessResult::forbidden', $output);
        $this->assertStringContainsString("#[PolicyAttribute(entityType: 'article')]", $output);
    }

    #[Test]
    public function it_requires_the_entity_option(): void
    {
        $tester = $this->createTester();
        $tester->execute(['ContentPolicy']);

        $this->assertSame(2, $tester->getExitCode());
        $this->assertStringContainsString('--entity is required', $tester->getStderr());
    }

    #[Test]
    public function it_rejects_a_malformed_grant(): void
    {
        $tester = $this->createTester();
        $tester->execute(['ContentPolicy', '--entity=article', '--grant=not-a-valid-grant']);

        $this->assertSame(2, $tester->getExitCode());
        $this->assertStringContainsString('Invalid --grant format', $tester->getStderr());
    }

    #[Test]
    public function it_rejects_a_grant_with_an_unsupported_operation(): void
    {
        $tester = $this->createTester();
        $tester->execute(['ContentPolicy', '--entity=article', '--grant=publish:some permission']);

        $this->assertSame(2, $tester->getExitCode());
        $this->assertStringContainsString('Invalid --grant format', $tester->getStderr());
    }

    #[Test]
    public function it_rejects_a_quote_breakout_payload_in_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(["foo', system('touch pwned'); //", '--entity=article']);

        $this->assertSame(1, $tester->getExitCode());
        $this->assertStringNotContainsString('system(', $tester->getStdout());
    }

    #[Test]
    public function it_rejects_a_path_traversal_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(['../evil', '--entity=article']);

        $this->assertSame(1, $tester->getExitCode());
    }

    #[Test]
    public function it_rejects_a_newline_injected_name(): void
    {
        $tester = $this->createTester();
        $tester->execute(["Foo\n}\nclass Evil {", '--entity=article']);

        $this->assertSame(1, $tester->getExitCode());
    }

    /**
     * Real access evaluation (#2848 acceptance): loads the generated class
     * into a real {@see EntityAccessHandler} and proves an authorized
     * account is allowed while an anonymous/permissionless account is
     * denied — not merely that the bytes look right.
     */
    #[Test]
    public function the_generated_policy_grants_authorized_accounts_and_denies_anonymous_and_permissionless_accounts(): void
    {
        $tester = $this->createTester();
        $tester->execute([
            'ContentPolicy',
            '--entity=article',
            '--grant=view:view article',
            '--grant=create:edit article',
            '--entity-class=' . MakePolicyCommandTestStubEntity::class,
        ]);
        self::assertSame(0, $tester->getExitCode());

        $policyClass = $this->loadGeneratedPolicy($tester->getStdout());
        $handler = new EntityAccessHandler([new $policyClass()]);

        $viewer = AuthorizationPrincipalFactory::authenticated(1, permissions: ['view article']);
        $anonymous = AuthorizationPrincipalFactory::anonymous();
        $permissionless = AuthorizationPrincipalFactory::authenticated(2, permissions: []);

        $entity = new MakePolicyCommandTestStubEntity();

        self::assertTrue($handler->check($entity, 'view', $viewer)->isAllowed());
        self::assertFalse($handler->check($entity, 'view', $anonymous)->isAllowed());
        self::assertFalse($handler->check($entity, 'view', $permissionless)->isAllowed());

        self::assertTrue($handler->checkCreateAccess('article', 'article', AuthorizationPrincipalFactory::authenticated(3, permissions: ['edit article']))->isAllowed());
        self::assertFalse($handler->checkCreateAccess('article', 'article', $anonymous)->isAllowed());
    }

    /**
     * #2788 review F1 precedent, applied to the manual path: a generated
     * policy with no `#[PolicyAttribute]` is never wired into
     * `EntityAccessHandler` at boot. Proves the attribute is present and
     * carries the declared entity id via reflection on the real loaded
     * class.
     */
    #[Test]
    public function the_generated_policy_class_carries_a_discoverable_policy_attribute(): void
    {
        $tester = $this->createTester();
        $tester->execute(['ContentPolicy', '--entity=article']);
        self::assertSame(0, $tester->getExitCode());

        $policyClass = $this->loadGeneratedPolicy($tester->getStdout());

        $attributes = new \ReflectionClass($policyClass)->getAttributes(PolicyAttribute::class);
        self::assertNotSame([], $attributes, 'Generated policy class must carry #[PolicyAttribute] to be discovered at boot.');
        self::assertSame(['article'], $attributes[0]->newInstance()->entityTypes);
    }

    private function loadGeneratedPolicy(string $policySource): string
    {
        $namespace = 'Waaseyaa\\CLI\\Tests\\MakePolicy' . bin2hex(random_bytes(4));
        $policySource = str_replace('namespace App\\Access;', 'namespace ' . $namespace . ';', $policySource);

        $file = tempnam(sys_get_temp_dir(), 'waaseyaa_make_policy_') . '.php';
        file_put_contents($file, $policySource);
        require $file;
        unlink($file);

        return $namespace . '\\ContentPolicy';
    }

    private function createTester(): CliTester
    {
        $provider = new MakeServiceProviderA();
        $definition = null;
        foreach ($provider->consoleCommands() as $cmd) {
            if ($cmd->name === 'make:policy') {
                $definition = $cmd;
                break;
            }
        }
        self::assertNotNull($definition);

        $container = new class implements ContainerInterface {
            public function get(string $id): mixed
            {
                if ($id === MakePolicyHandler::class) {
                    return new MakePolicyHandler();
                }
                throw new \RuntimeException("Not found: {$id}");
            }

            public function has(string $id): bool
            {
                return $id === MakePolicyHandler::class;
            }
        };

        return CliTester::for($definition, $container);
    }
}
