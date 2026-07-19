<?php

declare(strict_types=1);

namespace Waaseyaa\Genealogy;

use Waaseyaa\Access\Capability\CapabilityActorSemantics;
use Waaseyaa\Access\Capability\CapabilityDeclaration;
use Waaseyaa\Access\Capability\CapabilityReason;
use Waaseyaa\Access\Capability\CapabilityRegistryInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Audit\AuditedFieldRead;
use Waaseyaa\Audit\Contract\StrictPrivilegedReadLedgerInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\Foundation\ServiceProvider\Capability\ConfiguresHttpKernelInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Genealogy\Access\AuditedGenealogyInternalFieldReader;
use Waaseyaa\Genealogy\Access\GenealogyInternalFieldReaderInterface;
use Waaseyaa\Genealogy\Access\GenealogyRelationshipAccessPolicy;
use Waaseyaa\Genealogy\Entity\GenealogyEvent;
use Waaseyaa\Genealogy\Entity\GenealogyFamily;
use Waaseyaa\Genealogy\Entity\GenealogyPerson;
use Waaseyaa\Genealogy\Entity\GenealogyTree;
use Waaseyaa\Genealogy\Service\GenealogyFamilyService;
use Waaseyaa\Genealogy\Service\GenealogyPedigreeService;
use Waaseyaa\Genealogy\Ssr\GenealogySsrController;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class GenealogyServiceProvider extends ServiceProvider implements ConfiguresHttpKernelInterface
{
    public function register(): void
    {
        $this->entityType($this->treeEntityType());
        $this->entityType($this->personEntityType());
        $this->entityType($this->familyEntityType());
        $this->entityType($this->eventEntityType());

        $this->singleton(GenealogyInternalFieldReaderInterface::class, function (): GenealogyInternalFieldReaderInterface {
            $capabilities = $this->resolve(CapabilityRegistryInterface::class);
            $ledger = $this->resolve(StrictPrivilegedReadLedgerInterface::class);
            assert($capabilities instanceof CapabilityRegistryInterface);
            assert($ledger instanceof StrictPrivilegedReadLedgerInterface);
            $capabilities->register(new CapabilityDeclaration(
                issuer: 'genealogy.tombstone',
                reason: CapabilityReason::StrictAuditProjection,
                entityTypes: ['genealogy_person', 'genealogy_family', 'genealogy_event'],
                bundles: ['genealogy_person', 'genealogy_family', 'genealogy_event'],
                fields: ['deleted_at'],
                actorSemantics: [CapabilityActorSemantics::NoActingContext],
                maxTtlSeconds: 60,
                justification: 'Fail closed on soft-deleted genealogy content without exposing its Internal tombstone.',
            ));

            return new AuditedGenealogyInternalFieldReader(new AuditedFieldRead($capabilities, $ledger), $capabilities);
        });

        $this->singleton(GenealogyPedigreeService::class, function (): GenealogyPedigreeService {
            /** @var EntityTypeManager $manager */
            $manager = $this->resolve(EntityTypeManager::class);

            // R8 WP3: wire the kernel access handler so pedigree label
            // emission is field-access-gated too (defense-in-depth for the
            // R7 WP1 label channel), not just entity-level-gated.
            return new GenealogyPedigreeService($manager, $this->resolve(EntityAccessHandler::class));
        });

        $this->singleton(GenealogyFamilyService::class, function (): GenealogyFamilyService {
            /** @var EntityTypeManager $manager */
            $manager = $this->resolve(EntityTypeManager::class);

            return new GenealogyFamilyService($manager);
        });
    }

    public function configureHttpKernel(HttpKernel $kernel): void
    {
        GenealogyBootstrap::bind($kernel->getEntityTypeManager(), $kernel->getAccessHandler());
        $kernel->getAccessHandler()->addPolicy(
            new GenealogyRelationshipAccessPolicy(
                $kernel->getEntityTypeManager(),
                $kernel->getAccessHandler(),
            ),
        );
    }

    public function routes(WaaseyaaRouter $router, EntityTypeManager $entityTypeManager): void
    {
        $router->addRoute(
            'genealogy.landing',
            RouteBuilder::create('/genealogy')
                ->controller(GenealogySsrController::class . '::landing')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'genealogy.person',
            RouteBuilder::create('/genealogy/person/{id}')
                ->controller(GenealogySsrController::class . '::person')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->requirement('id', '\\d+')
                ->build(),
        );

        $router->addRoute(
            'genealogy.family',
            RouteBuilder::create('/genealogy/family/{id}')
                ->controller(GenealogySsrController::class . '::family')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->requirement('id', '\\d+')
                ->build(),
        );

        $router->addRoute(
            'genealogy.person.ancestors',
            RouteBuilder::create('/genealogy/person/{id}/ancestors')
                ->controller(GenealogySsrController::class . '::ancestorChart')
                ->allowAll()
                ->render()
                ->methods('GET')
                ->requirement('id', '\\d+')
                ->build(),
        );
    }

    private function treeEntityType(): EntityType
    {
        return EntityType::fromClass(
            GenealogyTree::class,
            group: 'content',
        );
    }

    private function personEntityType(): EntityType
    {
        return EntityType::fromClass(
            GenealogyPerson::class,
            group: 'content',
        );
    }

    private function familyEntityType(): EntityType
    {
        return EntityType::fromClass(
            GenealogyFamily::class,
            group: 'content',
        );
    }

    private function eventEntityType(): EntityType
    {
        return EntityType::fromClass(
            GenealogyEvent::class,
            group: 'content',
        );
    }
}
