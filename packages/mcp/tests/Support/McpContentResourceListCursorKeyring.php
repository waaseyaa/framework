<?php

declare(strict_types=1);

namespace Waaseyaa\Mcp\Tests\Support;

use Waaseyaa\Foundation\Log\Processor\RedactorProcessor;
use Waaseyaa\Foundation\Security\ApplicationMasterKeyring;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposePolicy;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeRegistry;
use Waaseyaa\Foundation\Security\ApplicationMasterPurposeStrategy;
use Waaseyaa\Foundation\Security\ApplicationSecret;
use Waaseyaa\Foundation\Security\SecretClass;
use Waaseyaa\Foundation\Security\SecretProviderInterface;
use Waaseyaa\Foundation\Security\SecretReference;
use Waaseyaa\Foundation\Security\SecretResolverRegistry;
use Waaseyaa\Foundation\Security\SensitiveValue;
use Waaseyaa\Mcp\Resource\ContentResourceListCursorCodec;
use Waaseyaa\Mcp\Rekey\McpContentResourceListCursorRekeyAdapter;

/** Test-only application-master keyring for MCP content-resource list cursors. */
final class McpContentResourceListCursorKeyring
{
    public static function create(int $activeVersion = 1): ApplicationMasterKeyring
    {
        $purposes = new ApplicationMasterPurposeRegistry();
        $purposes->register(new ApplicationMasterPurposePolicy(
            ApplicationSecret::PURPOSE_MCP_CONTENT_RESOURCE_LIST_CURSOR,
            'waaseyaa/mcp',
            ApplicationMasterPurposeStrategy::ReencryptCiphertext,
            ContentResourceListCursorCodec::DEFAULT_TTL_SECONDS,
            ContentResourceListCursorCodec::DEFAULT_TTL_SECONDS,
            McpContentResourceListCursorRekeyAdapter::ID,
            'expire-without-ciphertext-inventory',
        ));
        $purposes->freeze();

        $resolver = new SecretResolverRegistry(new RedactorProcessor(), 'testing');
        $resolver->registerProvider(new McpSyntheticMasterProvider());
        $resolver->allow(
            'mcp-synthetic-master',
            ApplicationMasterKeyring::PACKAGE,
            SecretClass::ApplicationMaster,
            ApplicationMasterKeyring::MASTER_PURPOSE,
            ['testing'],
        );
        ApplicationMasterKeyring::registerResolverConsumers($resolver);
        $resolver->freeze();

        return ApplicationMasterKeyring::fromReferences(
            $resolver,
            $activeVersion,
            SecretReference::create(
                'mcp-synthetic-master',
                'master-v' . $activeVersion,
                SecretClass::ApplicationMaster,
                ApplicationMasterKeyring::MASTER_PURPOSE,
            ),
            [],
            $purposes,
        );
    }
}

final class McpSyntheticMasterProvider implements SecretProviderInterface
{
    public function id(): string
    {
        return 'mcp-synthetic-master';
    }

    public function resolve(SecretReference $reference): SensitiveValue
    {
        return SensitiveValue::fromBytes(
            hash('sha256', $reference->identifier(), true),
            SecretClass::ApplicationMaster,
            $reference->identifier(),
        );
    }
}
