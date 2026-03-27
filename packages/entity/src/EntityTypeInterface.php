<?php

declare(strict_types=1);

namespace Waaseyaa\Entity;

interface EntityTypeInterface
{
    public function id(): string;

    public function getLabel(): string;

    public function getClass(): string;

    /** @return class-string<Storage\EntityStorageInterface> */
    public function getStorageClass(): string;

    /** @return array<string, string> Entity keys (id, uuid, label, bundle, revision, langcode, etc.) */
    public function getKeys(): array;

    public function isRevisionable(): bool;

    public function getRevisionDefault(): bool;

    public function isTranslatable(): bool;

    public function getBundleEntityType(): ?string;

    /** @return array<string, mixed> */
    public function getConstraints(): array;

    /** @return array<string, array<string, mixed>> Field definitions keyed by field name. */
    public function getFieldDefinitions(): array;

    /** @return string|null Admin sidebar group key (e.g. 'content', 'taxonomy'). */
    public function getGroup(): ?string;

    /** @return string|null Human-readable description of the entity type. */
    public function getDescription(): ?string;
}
