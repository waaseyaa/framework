<?php

declare(strict_types=1);

namespace Waaseyaa\Config\Schema;

/** Package-owned validation for constraints the closed schema dialect cannot express. @api */
interface ConfigSemanticValidatorInterface
{
    /**
     * The deterministic, portable identity of the semantic contract this
     * validator enforces — for example
     * `waaseyaa/workflows:workflows.assignments@1/semantic/1`.
     *
     * CFG-03 (#2458) binds this string into the schema identity's canonical
     * hash, so authored content guarded by a semantic contract cannot verify on
     * a host that runs a different contract or none at all. It must therefore
     * describe the contract, never a runtime object: the same installed
     * contract on two hosts must return byte-identical values, and any change
     * to the enforced semantics must advance it.
     *
     * Non-empty visible ASCII: one or more characters in the range
     * `\x21`-`\x7E`, so no whitespace and no control characters.
     */
    public function contract(): string;

    /**
     * @param array<string, mixed> $fields Structurally valid authored fields — the complete document
     * @return list<SchemaViolation>
     */
    public function validate(array $fields): array;
}
