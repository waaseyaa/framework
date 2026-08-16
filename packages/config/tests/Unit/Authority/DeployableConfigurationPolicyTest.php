<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Tests\Unit\Authority;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Config\Authority\ConfigurationAuthorityConflictException;
use Waaseyaa\Config\Authority\DeployableConfigurationPolicy;
use Waaseyaa\Config\Sync\ConfigSyncFile;

final class DeployableConfigurationPolicyTest extends TestCase
{
    #[Test]
    public function bootstrap_selectors_cannot_be_represented_as_deployable_entries(): void
    {
        $this->expectException(ConfigurationAuthorityConflictException::class);
        $this->expectExceptionMessage('immutable bootstrap authority');

        new ConfigSyncFile(
            entityType: 'config',
            entityId: 'sync_path',
            uuid: ConfigSyncFile::deterministicUuid('config', 'sync_path'),
            dependencies: [],
            langcode: 'en',
            fields: ['path' => 'storage/elsewhere'],
        );
    }

    #[Test]
    public function secret_typed_fields_refuse_raw_values(): void
    {
        $this->expectException(ConfigurationAuthorityConflictException::class);
        $this->expectExceptionMessage('opaque reference field');

        DeployableConfigurationPolicy::assertReferenceOnlyFields([
            'provider' => ['api_key' => 'raw-value-not-a-reference'],
        ]);
    }

    #[Test]
    public function secret_reference_fields_remain_deployable_without_resolving_values(): void
    {
        $file = new ConfigSyncFile(
            entityType: 'ai',
            entityId: 'providers',
            uuid: ConfigSyncFile::deterministicUuid('ai', 'providers'),
            dependencies: [],
            langcode: 'en',
            fields: ['providers' => [['api_key_env_var' => 'OPENAI_API_KEY']]],
        );

        self::assertSame('OPENAI_API_KEY', $file->fields['providers'][0]['api_key_env_var']);
    }
}

