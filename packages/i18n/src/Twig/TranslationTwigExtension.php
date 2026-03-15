<?php

declare(strict_types=1);

namespace Waaseyaa\I18n\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Waaseyaa\I18n\LanguageManagerInterface;
use Waaseyaa\I18n\TranslatorInterface;

final class TranslationTwigExtension extends AbstractExtension
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly LanguageManagerInterface $languageManager,
    ) {}

    /** @return TwigFunction[] */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('trans', $this->trans(...)),
            new TwigFunction('current_language', $this->currentLanguage(...)),
            new TwigFunction('available_languages', $this->availableLanguages(...)),
            new TwigFunction('lang_url', $this->langUrl(...)),
            new TwigFunction('lang_switch_url', $this->langSwitchUrl(...)),
        ];
    }

    /**
     * Translate a key.
     *
     * Usage: {{ trans('nav.communities') }}
     * Usage: {{ trans('greeting', {name: 'John'}) }}
     *
     * @param array<string, string> $params
     */
    public function trans(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }

    /**
     * Get the current language object as an array.
     *
     * Usage: {{ current_language().id }} → "en"
     * Usage: {{ current_language().label }} → "English"
     *
     * @return array{id: string, label: string, direction: string}
     */
    public function currentLanguage(): array
    {
        $lang = $this->languageManager->getCurrentLanguage();

        return [
            'id' => $lang->id,
            'label' => $lang->label,
            'direction' => $lang->direction,
        ];
    }

    /**
     * Get all available languages.
     *
     * Usage: {% for lang in available_languages() %}
     *
     * @return array<array{id: string, label: string, direction: string, is_current: bool}>
     */
    public function availableLanguages(): array
    {
        $current = $this->languageManager->getCurrentLanguage();
        $languages = [];

        foreach ($this->languageManager->getLanguages() as $lang) {
            $languages[] = [
                'id' => $lang->id,
                'label' => $lang->label,
                'direction' => $lang->direction,
                'is_current' => $lang->id === $current->id,
            ];
        }

        return $languages;
    }

    /**
     * Generate a URL with the current language prefix.
     *
     * Default language (en) gets no prefix. Others get /{lang}/path.
     *
     * Usage: {{ lang_url('/communities') }} → "/communities" (en) or "/oj/communities" (oj)
     */
    public function langUrl(string $path): string
    {
        $current = $this->languageManager->getCurrentLanguage();
        $default = $this->languageManager->getDefaultLanguage();

        if ($current->id === $default->id) {
            return $path;
        }

        return '/' . $current->id . $path;
    }

    /**
     * Generate a URL for switching to a specific language.
     *
     * Usage: {{ lang_switch_url('oj', '/communities') }} → "/oj/communities"
     * Usage: {{ lang_switch_url('en', '/communities') }} → "/communities"
     */
    public function langSwitchUrl(string $langId, string $path): string
    {
        $default = $this->languageManager->getDefaultLanguage();

        if ($langId === $default->id) {
            return $path;
        }

        return '/' . $langId . $path;
    }
}
