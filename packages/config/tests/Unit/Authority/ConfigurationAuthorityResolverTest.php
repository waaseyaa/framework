<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Authority;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ConfigurationAuthorityConflictException;
use Waaseyaa\Config\Authority\ConfigurationAuthorityResolver;

final class ConfigurationAuthorityResolverTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/waaseyaa-cfg01-'.bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root, 0700, true));
    }

    protected function tearDown(): void
    {
        @rmdir($this->root.'/storage/config-sync');
        @rmdir($this->root.'/storage');
        @rmdir($this->root);
    }

    #[Test]
    public function absent_selectors_use_the_project_relative_canonical_default(): void
    {
        $context = (new ConfigurationAuthorityResolver())->resolve(
            $this->root,
            'sqlite-primary',
            [],
            [],
        );

        self::assertSame($this->root.'/storage/config-sync', $context->syncPath);
        self::assertSame(['default'], $context->selectorProvenance);
        self::assertFalse($context->usedLegacySelector());
        self::assertSame('sqlite-primary', $context->databaseIdentity);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $context->authorityId);
    }

    #[Test]
    public function equivalent_canonical_and_legacy_selectors_collapse_with_provenance(): void
    {
        $context = (new ConfigurationAuthorityResolver())->resolve(
            $this->root,
            'sqlite-primary',
            ['config' => ['sync_path' => 'storage/../storage/config-sync'], 'config_dir' => './storage/config-sync'],
            ['WAASEYAA_CONFIG_SYNC_PATH' => 'storage/config-sync', 'WAASEYAA_CONFIG_DIR' => './storage/config-sync'],
        );

        self::assertSame($this->root.'/storage/config-sync', $context->syncPath);
        self::assertSame(
            ['config.sync_path', 'WAASEYAA_CONFIG_SYNC_PATH', 'config_dir', 'WAASEYAA_CONFIG_DIR'],
            $context->selectorProvenance,
        );
        self::assertTrue($context->usedLegacySelector());
    }

    #[Test]
    public function conflicting_selectors_fail_closed(): void
    {
        $this->expectException(ConfigurationAuthorityConflictException::class);
        $this->expectExceptionMessage('configuration sync selectors disagree');

        (new ConfigurationAuthorityResolver())->resolve(
            $this->root,
            'sqlite-primary',
            ['config' => ['sync_path' => 'storage/config-sync'], 'config_dir' => 'storage/other'],
            [],
        );
    }

    #[Test]
    public function traversal_outside_project_is_refused_by_default(): void
    {
        $this->expectException(ConfigurationAuthorityConflictException::class);
        $this->expectExceptionMessage('outside the project boundary');

        (new ConfigurationAuthorityResolver())->resolve(
            $this->root,
            'sqlite-primary',
            ['config' => ['sync_path' => '../external']],
            [],
        );
    }

    #[Test]
    public function explicit_external_local_policy_allows_a_lexically_normalized_path(): void
    {
        $context = (new ConfigurationAuthorityResolver())->resolve(
            $this->root,
            'sqlite-primary',
            ['config' => ['sync_path' => '../external', 'allow_external_sync_path' => true]],
            [],
        );

        self::assertSame(dirname($this->root).'/external', $context->syncPath);
    }

    #[Test]
    public function authority_identity_changes_with_database_or_sync_authority(): void
    {
        $resolver = new ConfigurationAuthorityResolver();
        $first = $resolver->resolve($this->root, 'db-a', [], []);
        $same = $resolver->resolve($this->root, 'db-a', [], []);
        $otherDb = $resolver->resolve($this->root, 'db-b', [], []);
        $otherPath = $resolver->resolve($this->root, 'db-a', ['config' => ['sync_path' => 'config/sync']], []);

        self::assertSame($first->authorityId, $same->authorityId);
        self::assertNotSame($first->authorityId, $otherDb->authorityId);
        self::assertNotSame($first->authorityId, $otherPath->authorityId);
    }
}
