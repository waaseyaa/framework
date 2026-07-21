<?php

declare(strict_types=1);

namespace Waaseyaa\Media\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\AuthorizationPrincipal;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Access\PolicySubjectViewInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Media\Media;
use Waaseyaa\Media\MediaAccessPolicy;

#[CoversClass(EntityAccessHandler::class)]
final class ContextualMediaProtectedFieldReadTest extends TestCase
{
    #[Test]
    public function application_entity_view_grant_releases_protected_media_metadata(): void
    {
        $media = $this->media();
        $principal = new AuthorizationPrincipal(42, true, ['band_member'], [], 'claims-1');
        $handler = new EntityAccessHandler([
            new MediaAccessPolicy(),
            $this->memberPolicy(),
        ]);

        self::assertTrue($handler->check($media, 'view', $principal)->isAllowed());
        self::assertTrue($handler->checkProtectedFieldRead(
            $principal,
            $media->entityStructure(),
            $this->emptySubject(),
            'description',
            $media,
        )->isAllowed());
    }

    #[Test]
    public function application_entity_view_denial_still_blocks_protected_media_metadata(): void
    {
        $media = $this->media();
        $principal = new AuthorizationPrincipal(43, true, ['subscriber'], [], 'claims-2');
        $handler = new EntityAccessHandler([
            new MediaAccessPolicy(),
            $this->memberPolicy(),
        ]);

        self::assertTrue($handler->check($media, 'view', $principal)->isForbidden());
        self::assertTrue($handler->checkProtectedFieldRead(
            $principal,
            $media->entityStructure(),
            $this->emptySubject(),
            'description',
            $media,
        )->isForbidden());
    }

    private function media(): Media
    {
        return new Media([
            'mid' => 389,
            'bundle' => 'members_document',
            'name' => 'Band governance document',
            'description' => 'Members-only document',
            'status' => true,
            'uid' => 80,
        ]);
    }

    private function memberPolicy(): AccessPolicyInterface
    {
        return new class implements AccessPolicyInterface {
            public function appliesTo(string $entityTypeId): bool
            {
                return $entityTypeId === 'media';
            }

            public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
            {
                if ($entity->bundle() !== 'members_document' || $operation !== 'view') {
                    return AccessResult::neutral();
                }

                return in_array('band_member', $account->getRoles(), true)
                    ? AccessResult::allowed('Band Members may view member documents.')
                    : AccessResult::forbidden('Member documents require Band Member access.');
            }

            public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
            {
                return AccessResult::neutral();
            }
        };
    }

    private function emptySubject(): PolicySubjectViewInterface
    {
        return new class implements PolicySubjectViewInterface {
            public function fields(): array
            {
                return [];
            }

            public function get(string $fieldName): mixed
            {
                return null;
            }
        };
    }
}
