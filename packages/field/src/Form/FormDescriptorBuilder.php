<?php

declare(strict_types=1);

namespace Waaseyaa\Field\Form;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;

/**
 * Builds an ordered list of FormFieldDescriptor value objects for a given
 * entity + bundle pair.
 *
 * No HTML, no Twig, no markup of any kind is produced. This builder emits
 * pure value objects; the consumer's template layer is responsible for
 * rendering.
 *
 * When an EntityAccessHandler and AccountInterface are both provided, the
 * builder upgrades any field whose 'update' access returns Forbidden to
 * readOnly=true, regardless of the field definition's own isReadOnly() flag.
 * Neutral and Allowed results leave the definition's readOnly setting intact
 * (open-by-default field access semantics).
 * @api
 */
final class FormDescriptorBuilder
{
    public function __construct(
        private readonly FieldDefinitionRegistryInterface $registry,
        private readonly ?EntityAccessHandler $accessHandler = null,
    ) {}

    /**
     * Build descriptors for all bundle-scoped fields of the given entity.
     *
     * Returns descriptors in registry insertion order (declaration order from
     * the BundleTemplateCompiler). Returns an empty list when no fields are
     * registered for the bundle — never throws.
     *
     * @return list<FormFieldDescriptor>
     * @param \Waaseyaa\Access\AuthorizationPrincipalInterface|null $account
     */
    public function build(
        EntityInterface $entity,
        string $bundle,
        ?AccountInterface $account = null,
    ): array {
        $fields = $this->registry->bundleFieldsFor($entity->getEntityTypeId(), $bundle);

        if ($fields === []) {
            return [];
        }

        $descriptors = [];

        foreach ($fields as $field) {
            $rawValue = $entity->get($field->getName());
            $value = ($rawValue === null || $rawValue === '') ? null : $rawValue;

            $readOnly = $field->isReadOnly();

            if ($this->accessHandler !== null && $account !== null) {
                $accessResult = $this->accessHandler->checkFieldAccess(
                    $entity,
                    $field->getName(),
                    'update',
                    $account,
                );

                if ($accessResult->isForbidden()) {
                    $readOnly = true;
                }
            }

            $fieldLabel = $field->getLabel();
            $label = $fieldLabel !== '' ? $fieldLabel : ucfirst($field->getName());

            $descriptors[] = new FormFieldDescriptor(
                name: $field->getName(),
                type: $field->getType(),
                label: $label,
                group: $field->getGroup(),
                value: $value,
                readOnly: $readOnly,
                required: $field->isRequired(),
            );
        }

        return $descriptors;
    }
}
