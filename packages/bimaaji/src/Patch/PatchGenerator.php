<?php

declare(strict_types=1);

namespace Waaseyaa\Bimaaji\Patch;

use Waaseyaa\Bimaaji\Mutation\MutationResult;

final class PatchGenerator
{
    private const string IDENTIFIER_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private readonly PhpFileBuilder $builder;

    public function __construct()
    {
        $this->builder = new PhpFileBuilder();
    }

    public function generate(MutationResult $result): PatchSet
    {
        if (!$result->isSuccess()) {
            throw new \InvalidArgumentException('Cannot generate patches from a failed mutation result');
        }

        $request = $result->request;

        return match ($request->operation) {
            'add_field' => $this->generateAddField($request->entityType, $request->field, $request->parameters),
            default => new PatchSet([]),
        };
    }

    private function generateAddField(string $entityType, ?string $fieldName, array $parameters): PatchSet
    {
        if ($fieldName === null) {
            return new PatchSet([]);
        }

        $content = $this->builder->buildFieldDefinitionPatch($entityType, $fieldName, $parameters);
        $filePath = "src/Entity/fields/{$entityType}/{$fieldName}.php";

        $entry = new PatchEntry(
            filePath: $filePath,
            content: $content,
            diffText: "--- /dev/null\n+++ b/{$filePath}\n@@ -0,0 +1 @@\n+{$content}",
            contentHash: hash('sha256', $content),
            unsafe: !$this->isSafeIdentifier($entityType) || !$this->isSafeIdentifier($fieldName),
        );

        return new PatchSet([$entry]);
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match(self::IDENTIFIER_PATTERN, $identifier) === 1;
    }
}
