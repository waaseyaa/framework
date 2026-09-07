<?php

declare(strict_types=1);

/**
 * Recipe provider activation packaged proof (#2857, ADR-025 D-15.2).
 *
 * Boots a real packaged kernel from this project's own installed vendor tree
 * and reports, via stdout markers only, whether the `governed_authoring`
 * recipe's page-builder surface and `page_layout` field are reachable. This
 * one probe is copied into the consumer and run twice by
 * `check-site-recipe-provider-activation`:
 *
 *   - once after `site:init` + `install:init` published the recipe's fixed
 *     provider registration into literal root `composer.json` (expect both
 *     markers present);
 *   - once more after that literal-root entry alone is removed, with the
 *     recipe's own Composer fragment file and generated provider class left
 *     untouched on disk (expect the "NOT registered" marker) — proving
 *     `PackageManifestCompiler`'s provider-discovery authority is literal
 *     root `composer.json`, never the fragment or the mere presence of the
 *     class file.
 *
 * A kernel-boot failure is the only case that exits non-zero; both the
 * "resolved" and "NOT registered" observations are successful probe runs,
 * distinguished by the harness with `grep`.
 */

require __DIR__ . '/vendor/autoload.php';

use Waaseyaa\Entity\Field\FieldDefinitionRegistryInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\PageBuilder\Surface\PageBuilderSurfaceRegistry;

try {
    $kernel = new HttpKernel(__DIR__);
    new ReflectionMethod($kernel, 'boot')->invoke($kernel);
    fwrite(STDOUT, "recipe-provider-activation kernel boot OK\n");

    $resolver = $kernel->getHttpServiceResolver();

    $registry = null;
    try {
        $registry = $resolver->resolve(PageBuilderSurfaceRegistry::class);
    } catch (\Throwable) {
        $registry = null;
    }

    $surfaceResolved = false;
    if ($registry instanceof PageBuilderSurfaceRegistry) {
        try {
            $registry->get('page');
            $surfaceResolved = true;
        } catch (\Throwable) {
            $surfaceResolved = false;
        }
    }
    fwrite(STDOUT, $surfaceResolved
        ? "recipe-provider-activation page surface: resolved\n"
        : "recipe-provider-activation page surface: NOT registered\n");

    $fieldRegistered = false;
    try {
        $fields = $resolver->resolve(FieldDefinitionRegistryInterface::class);
        if ($fields instanceof FieldDefinitionRegistryInterface) {
            $fieldRegistered = isset($fields->bundleFieldsFor('node', 'page')['page_layout']);
        }
    } catch (\Throwable) {
        $fieldRegistered = false;
    }
    fwrite(STDOUT, $fieldRegistered
        ? "recipe-provider-activation page_layout field: present\n"
        : "recipe-provider-activation page_layout field: NOT present\n");

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '::error::recipe-provider-activation kernel boot FAILED: ' . $e::class . ': ' . $e->getMessage() . "\n");
    for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
        fwrite(STDERR, '  previous: ' . $p::class . ': ' . $p->getMessage() . "\n");
    }
    exit(1);
}
