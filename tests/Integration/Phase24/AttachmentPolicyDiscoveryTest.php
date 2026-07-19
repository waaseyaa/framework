<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Phase24;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Attachment\AttachmentServiceProvider;
use Waaseyaa\Attachment\Policy\ParentDelegatedAccessPolicy;
use Waaseyaa\Foundation\Discovery\PackageManifest;
use Waaseyaa\Foundation\Kernel\AbstractKernel;

/**
 * FR-011: ParentDelegatedAccessPolicy is auto-discovered at kernel boot
 * without any manual ServiceProvider::boot() registration.
 *
 * The test boots a minimal kernel with the AttachmentServiceProvider and
 * a manifest that declares ParentDelegatedAccessPolicy for the 'attachment'
 * entity type. After WP02's two-phase resolver, the policy must be present
 * in the EntityAccessHandler without any manual wiring.
 *
 * Note: The manifest is injected by overriding compileManifest() to avoid
 * a full composer vendor scan in a temporary project root — but all real
 * kernel boot logic (provider registration, service binding, two-phase
 * resolver) executes normally. No aspect of policy discovery is mocked.
 */
#[CoversNothing]
final class AttachmentPolicyDiscoveryTest extends TestCase
{
    private string $projectRoot = '';

    protected function setUp(): void
    {
        $this->projectRoot = sys_get_temp_dir() . '/waaseyaa_attachment_policy_test_' . uniqid();

        mkdir($this->projectRoot . '/config', 0o755, true);
        mkdir($this->projectRoot . '/storage', 0o755, true);

        file_put_contents(
            $this->projectRoot . '/config/waaseyaa.php',
            "<?php return ['database' => ':memory:', 'environment' => 'testing'];",
        );
    }

    protected function tearDown(): void
    {
        // Reset static field registry used by ContentEntityBase.
        $prop = new \ReflectionProperty(\Waaseyaa\Entity\ContentEntityBase::class, 'fieldRegistry');
        $prop->setValue(null, null);

        if ($this->projectRoot === '' || !is_dir($this->projectRoot)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->projectRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($this->projectRoot);
    }

    #[Test]
    public function parent_delegated_access_policy_is_auto_discovered_without_manual_registration(): void
    {
        $projectRoot = $this->projectRoot;

        $kernel = new class ($projectRoot) extends AbstractKernel {
            public function publicBoot(): void
            {
                $this->boot();
            }

            /**
             * Override manifest compilation to inject a manifest that declares
             * ParentDelegatedAccessPolicy for 'attachment'. The rest of the boot
             * sequence — provider registration, service binding, two-phase resolver
             * — executes exactly as in production.
             *
             * No aspect of policy discovery or resolver logic is bypassed here.
             */
            protected function compileManifest(): void
            {
                $this->manifest = new PackageManifest(
                    providers: [AttachmentServiceProvider::class],
                    policies: [
                        ParentDelegatedAccessPolicy::class => ['attachment'],
                    ],
                );
            }
        };

        // Boot must succeed — no PolicyInstantiationException.
        $kernel->publicBoot();

        $handler = $kernel->getAccessHandler();

        // Verify via reflection that the handler contains a ParentDelegatedAccessPolicy instance.
        $policiesProperty = new \ReflectionProperty($handler, 'policies');
        /** @var list<\Waaseyaa\Access\AccessPolicyInterface> $policies */
        $policies = $policiesProperty->getValue($handler);

        $attachmentPolicies = array_filter(
            $policies,
            static fn($p) => $p instanceof ParentDelegatedAccessPolicy,
        );

        self::assertNotEmpty(
            $attachmentPolicies,
            'Expected ParentDelegatedAccessPolicy to be auto-discovered for the attachment entity type '
            . 'via the two-phase container-resolved registry (WP02, closes #1519).',
        );
    }
}
