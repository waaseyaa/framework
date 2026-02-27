<?php

declare(strict_types=1);

namespace Aurora\Entity;

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

    public function isTranslatable(): bool;

    public function getBundleEntityType(): ?string;

    /** @return array<string, mixed> */
    public function getConstraints(): array;
}
