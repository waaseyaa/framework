<?php

declare(strict_types=1);

namespace Waaseyaa\User;

/** Consumer-selected auth mail templates and branding, excluding security values. @api */
final readonly class AuthMailPresentation
{
    /**
     * @param array<string, bool|float|int|string|null> $variables
     */
    public function __construct(
        public string $subject,
        public string $htmlTemplate,
        public string $textTemplate,
        public array $variables = [],
    ) {
        if (trim($subject) === '' || trim($htmlTemplate) === '' || trim($textTemplate) === '') {
            throw new \InvalidArgumentException('Auth mail presentation requires subject and both template names.');
        }
        foreach (['user_name', 'reset_url', 'verify_url', 'home_url'] as $reserved) {
            if (array_key_exists($reserved, $variables)) {
                throw new \InvalidArgumentException(sprintf('Auth mail variable "%s" is Framework-owned.', $reserved));
            }
        }
    }
}
