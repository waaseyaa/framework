<?php

declare(strict_types=1);

namespace Waaseyaa\SSR;

use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Waaseyaa\Foundation\Http\HttpServiceResolverInterface;
use Waaseyaa\I18n\LanguageManagerInterface;
use Waaseyaa\Routing\Language\AcceptHeaderNegotiator;
use Waaseyaa\Routing\Language\LanguageNegotiator;
use Waaseyaa\Routing\Language\UrlPrefixNegotiator;

/**
 * Resolves content language from URL prefixes and Accept-Language headers.
 *
 * Extracted from SsrPageHandler (#572) to isolate language detection,
 * negotiation, and path prefix stripping into a single-responsibility service.
 */
final class LanguageResolver
{
    public function __construct(
        private readonly ?HttpServiceResolverInterface $serviceResolver = null,
    ) {}

    /**
     * @return array{langcode: string, alias_path: string}
     */
    public function resolveRenderLanguageAndAliasPath(string $path, HttpRequest $request): array
    {
        $manager = $this->resolveLanguageManager();
        if ($manager === null) {
            return [
                'langcode' => 'en',
                'alias_path' => $path,
            ];
        }

        $availableLanguages = array_keys($manager->getLanguages());

        $headers = [];
        $acceptLanguage = $request->headers->get('Accept-Language');
        if (is_string($acceptLanguage) && trim($acceptLanguage) !== '') {
            $headers['accept-language'] = $acceptLanguage;
        }

        $context = new LanguageNegotiator(
            negotiators: [new UrlPrefixNegotiator(), new AcceptHeaderNegotiator()],
            languageManager: $manager,
        )->negotiate($path, $headers);
        $negotiatedLanguage = $context->getContentLanguage();
        $manager->setCurrentLanguage($negotiatedLanguage);
        $langcode = $negotiatedLanguage->id;

        $aliasPath = $path;
        $prefixLangcode = $this->detectLanguagePrefixFromPath($path, $availableLanguages);
        if ($prefixLangcode !== null) {
            $aliasPath = $this->stripLanguagePrefix($path, $prefixLangcode);
        }

        return [
            'langcode' => $langcode,
            'alias_path' => $aliasPath,
        ];
    }

    /**
     * Strip a language prefix from the path and activate the language on the
     * shared LanguageManager. Called by the kernel BEFORE route matching so
     * that language-prefixed paths like /oj/communities resolve correctly.
     *
     * Returns the path with the prefix removed (or unchanged if no prefix).
     */
    public function stripLanguagePrefixForRouting(string $path): string
    {
        $manager = $this->resolveLanguageManager();
        if ($manager === null) {
            return $path;
        }
        $manager->resetToDefault();
        $availableLanguages = array_keys($manager->getLanguages());
        $defaultLanguage = $manager->getDefaultLanguage();

        $prefix = $this->detectLanguagePrefixFromPath($path, $availableLanguages);
        if ($prefix === null || $prefix === $defaultLanguage->id) {
            return $path;
        }

        $language = $manager->getLanguage($prefix);
        if ($language !== null) {
            $manager->setCurrentLanguage($language);
        }

        return $this->stripLanguagePrefix($path, $prefix);
    }

    /**
     * @param list<string> $availableLanguages
     */
    public function detectLanguagePrefixFromPath(string $path, array $availableLanguages): ?string
    {
        $segments = explode('/', ltrim($path, '/'));
        if ($segments === [] || $segments[0] === '') {
            return null;
        }

        $prefix = $segments[0];
        if (in_array($prefix, $availableLanguages, true)) {
            return $prefix;
        }

        return null;
    }

    public function stripLanguagePrefix(string $path, string $langcode): string
    {
        $prefix = '/' . ltrim($langcode, '/');
        if ($path === $prefix) {
            return '/';
        }
        if (str_starts_with($path, $prefix . '/')) {
            $stripped = substr($path, strlen($prefix));
            return $stripped !== '' ? $stripped : '/';
        }

        return $path;
    }

    /**
     * Resolve the app-level LanguageManager via serviceResolver.
     * Returns null if no manager is registered.
     */
    private function resolveLanguageManager(): ?LanguageManagerInterface
    {
        if ($this->serviceResolver !== null) {
            $manager = $this->serviceResolver->resolve(LanguageManagerInterface::class);
            if ($manager instanceof LanguageManagerInterface) {
                return $manager;
            }
        }

        return null;
    }
}
