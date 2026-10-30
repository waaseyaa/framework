<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Tests\Unit\Install;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Executes the shipped examples, rather than maintaining parallel PHP fixtures. */
#[CoversNothing]
final class AppDevelopmentExamplesTest extends TestCase
{
    public function testRegistrationBuildsTheDeclaredFields(): void
    {
        $this->execute(
            $this->example('Entity Class')
            . "\nnamespace App\\Provider;\nuse App\\Entity\\Goal;\nuse Waaseyaa\\Entity\\EntityType;\n"
            . 'final class GoalServiceProvider extends \\Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider {'
            . $this->example('EntityType Registration (Service Provider)') . '}',
            <<<'PHP'
                $provider = new \App\Provider\GoalServiceProvider();
                $provider->register();
                $type = $provider->getEntityTypes()[0];
                if ($type->id() !== 'goal' || $type->getKeys()['label'] !== 'title') {
                    throw new \RuntimeException('Registration identity mismatch');
                }
                foreach (['title', 'description', 'status', 'due_date'] as $name) {
                    $field = $type->getFieldDefinitions()[$name] ?? null;
                    if (!$field instanceof \Waaseyaa\Field\FieldDefinitionInterface
                        || $field->getReadLevel() !== \Waaseyaa\Entity\FieldReadLevel::Public) {
                        throw new \RuntimeException('Missing classified field: ' . $name);
                    }
                }
                new \App\Entity\Goal(['title' => 'Synthetic goal']);
                PHP,
        );
    }

    public function testPolicyCompilesAndPreservesPermissionRefusal(): void
    {
        $this->execute($this->example('Entity Class') . "\n" . $this->example('Access Policy'), <<<'PHP'
            $account = new class implements \Waaseyaa\Access\AccountInterface {
                public bool $admin = false;
                public function id(): int|string { return 0; }
                public function hasPermission(string $permission): bool { return $this->admin && $permission === 'administer goals'; }
                public function getRoles(): array { return []; }
                public function isAuthenticated(): bool { return false; }
            };
            $policy = new \App\Access\GoalAccessPolicy();
            $goal = new \App\Entity\Goal();
            if (!$policy->appliesTo('goal') || $policy->appliesTo('other')
                || !$policy->access($goal, 'view', $account)->isAllowed()
                || $policy->access($goal, 'update', $account)->isAllowed()
                || $policy->createAccess('goal', '', $account)->isAllowed()) {
                throw new \RuntimeException('Anonymous policy boundary failed');
            }
            $account->admin = true;
            if (!$policy->access($goal, 'update', $account)->isAllowed()
                || !$policy->createAccess('goal', '', $account)->isAllowed()) {
                throw new \RuntimeException('Administrator policy boundary failed');
            }
            PHP);
    }

    public function testControllerUsesTheAccountInterfaceAndResponseContract(): void
    {
        $this->execute($this->example('Controller'), <<<'PHP'
            $method = new \ReflectionMethod(\App\Controller\GoalController::class, 'list');
            if ($method->getParameters()[2]->getType()->getName() !== \Waaseyaa\Access\AccountInterface::class) {
                throw new \RuntimeException('Incorrect account interface');
            }
            if (!class_exists($method->getReturnType()->getName())) {
                throw new \RuntimeException('Missing response type');
            }
            PHP);
    }

    public function testRouteRetainsItsPermissionRequirement(): void
    {
        $this->execute(
            $this->example('Controller')
            . "\nnamespace App\\Provider;\n"
            . 'final class Routes extends \\Waaseyaa\\Foundation\\ServiceProvider\\ServiceProvider { public function register(): void {}'
            . $this->example('Route Registration') . '}',
            <<<'PHP'
                $router = new \Waaseyaa\Routing\WaaseyaaRouter();
                $manager = new \Waaseyaa\Entity\EntityTypeManager(new \Symfony\Component\EventDispatcher\EventDispatcher());
                (new \App\Provider\Routes())->routes($router, $manager);
                $route = $router->getRouteCollection()->get('goal.list');
                if ($route->getPath() !== '/api/goals'
                    || $route->getMethods() !== ['GET']
                    || $route->getOption('_permission') !== 'access content') {
                    throw new \RuntimeException('Route lost its permission requirement');
                }
                PHP,
        );
    }

    private function example(string $heading): string
    {
        $source = file_get_contents(\dirname(__DIR__, 3) . '/resources/skills/app-development/SKILL.md');
        self::assertIsString($source);
        self::assertSame(1, preg_match('/### ' . preg_quote($heading, '/') . '\R.*?```php\R(.*?)```/s', $source, $match));

        return str_replace(['<?php', 'declare(strict_types=1);'], '', $match[1]);
    }

    private function execute(string $examples, string $assertions): void
    {
        $root = \dirname(__DIR__, 5);
        $code = 'require ' . var_export($root . '/vendor/autoload.php', true) . ';'
            . 'eval(' . var_export($examples, true) . ');' . $assertions;
        $process = new Process([PHP_BINARY, '-d', 'display_errors=1', '-r', $code]);
        $process->setTimeout(30);
        $process->run();
        self::assertSame(0, $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
