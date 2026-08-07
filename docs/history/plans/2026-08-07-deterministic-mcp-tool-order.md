# Deterministic MCP tool order

Issue: #2295

## Problem

`AgentToolRegistryBridge::getTools()` copied the underlying registry's enumeration order directly into `tools/list`. That order can vary with Composer manifest and optimized-classmap discovery, so the same installed tool set could produce different protocol responses and break consumers that compare a catalogue deterministically.

## Decision

Sort the protocol-visible list by `AgentTool::name` in `AgentToolRegistryBridge`. Do not sort or otherwise change `AttributeToolRegistry`: internal callers may intentionally rely on its discovery order, while MCP clients need a stable wire contract.

## Verification

- A red-first unit test constructs the same tool set in opposite registry orders and requires identical alphabetical bridge output.
- MCP package and integration tests pin unchanged lookup and execution behavior.
- The canonical repository verification suite validates package boundaries, public surface, static analysis, and all framework tests.

## Non-goals

- No site-side sorting or snapshot workaround.
- No change to tool names, descriptors, authorization, execution, or registry semantics.
- No release or deployment as part of this change.
