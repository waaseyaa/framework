<?php

declare(strict_types=1);

namespace Waaseyaa\AI\Tools\Registry;

use Waaseyaa\AI\Tools\AgentTool;
use Waaseyaa\AI\Tools\ToolNotFoundException;
use Waaseyaa\AI\Tools\ToolRegistryInterface;

/**
 * Exact-membership decorator over a {@see ToolRegistryInterface}: a tool is
 * visible only if its **id** is on a closed list.
 *
 * ## Why an id allowlist and not a capability allowlist
 *
 * {@see CapabilityScopedToolRegistry} narrows by capability, which is an *open*
 * set: `AbstractAgentTool::requireCapability()` matches a capability string and
 * consults no roster, so any class added later carrying
 * `#[AsAgentTool(capability: 'bimaaji.read')]` joins a `bimaaji.read` tier the
 * moment it is discovered — with no review of what it reads and no signal to
 * the operator. ADR-022 D-7.1 settles that in the id allowlist's favour for the
 * local development plane, and this is the registry that enforces it.
 *
 * ## The two controls are layered, never alternatives
 *
 * Membership here narrows visibility; it never widens authority. A tool on this
 * list whose capability the acting principal does not hold is still refused by
 * `requireCapability()` (D-7.2). Compose this *around* a capability scope to
 * get both: the capability scope answers "is this tier for that capability",
 * this answers "is this exact tool on the reviewed list", and the tool itself
 * answers "may this principal run it".
 *
 * ## Matching is exact
 *
 * Never a prefix, pattern, or wildcard — the same discipline the local
 * plane's principal applies to its capability membership test. A closed list
 * that grows by pattern is not a closed list.
 *
 * The list is supplied by the caller rather than read from a profile class.
 * The local plane's default profile lives in `waaseyaa/ai-agent`, which is
 * deliberately absent from every production require closure (ADR-022 D-3.0),
 * while this registry ships in the production-present `waaseyaa/ai-tools`.
 * #2659 passes that profile's tool ids in; nothing developer-only is named
 * here to make that possible, and nothing here can widen it.
 *
 * @api
 */
final class ToolIdAllowlistRegistry implements ToolRegistryInterface
{
    /**
     * @param list<string> $allowedToolIds Tool ids (the `name:` argument of
     *        `#[AsAgentTool]`) admitted by exact string match. An EMPTY list
     *        exposes nothing: the fail-closed reading of "no profile was
     *        configured" is "no tool is admitted", never "every tool is".
     */
    public function __construct(
        private readonly ToolRegistryInterface $inner,
        private readonly array $allowedToolIds,
    ) {}

    public function register(AgentTool $tool): void
    {
        if (!$this->isVisible($tool)) {
            throw new \LogicException(sprintf(
                'Refusing to register tool "%s": its id is not on this profile\'s allowlist. '
                . 'Add the id to the profile deliberately rather than registering around it.',
                $tool->name,
            ));
        }

        $this->inner->register($tool);
    }

    public function get(string $name): AgentTool
    {
        $tool = $this->inner->get($name);
        if (!$this->isVisible($tool)) {
            // A withheld tool is hidden behind the same error an unregistered
            // name yields: "not on the profile" and "does not exist" are
            // deliberately indistinguishable to the caller.
            throw ToolNotFoundException::forName($name);
        }

        return $tool;
    }

    public function has(string $name): bool
    {
        if (!$this->inner->has($name)) {
            return false;
        }

        return $this->isVisible($this->inner->get($name));
    }

    public function all(): iterable
    {
        foreach ($this->inner->all() as $tool) {
            if ($this->isVisible($tool)) {
                yield $tool;
            }
        }
    }

    private function isVisible(AgentTool $tool): bool
    {
        return \in_array($tool->name, $this->allowedToolIds, true);
    }
}
