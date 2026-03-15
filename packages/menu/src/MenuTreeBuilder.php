<?php

declare(strict_types=1);

namespace Waaseyaa\Menu;

/**
 * Builds a hierarchical tree from a flat list of menu links.
 *
 * Links are sorted by weight, then organized into a parent-child
 * hierarchy based on each link's parentId.
 */
final class MenuTreeBuilder
{
    /**
     * Build a tree structure from flat menu links.
     *
     * @param MenuLink[] $links Flat array of menu links for a single menu.
     * @return MenuTreeElement[] Root-level tree elements with children nested.
     */
    public function buildTree(array $links): array
    {
        if ($links === []) {
            return [];
        }

        // Sort links by weight (ascending), preserving relative order for equal weights.
        usort($links, static fn(MenuLink $a, MenuLink $b): int => $a->getWeight() <=> $b->getWeight());

        // Create tree elements indexed by link ID.
        /** @var array<int|string, MenuTreeElement> $elements */
        $elements = [];
        foreach ($links as $link) {
            $id = $link->id();
            if ($id !== null) {
                $elements[$id] = new MenuTreeElement($link);
            }
        }

        // Build the hierarchy.
        $roots = [];
        foreach ($elements as $id => $element) {
            $parentId = $element->link->getParentId();

            if ($parentId === null || $parentId === 0 || !isset($elements[$parentId])) {
                // Root-level element (or orphan whose parent is not in the set).
                $roots[] = $element;
            } else {
                // Attach as child of parent.
                $elements[$parentId]->children[] = $element;
            }
        }

        return $roots;
    }
}
