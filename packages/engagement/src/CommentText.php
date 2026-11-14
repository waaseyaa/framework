<?php

declare(strict_types=1);

namespace Waaseyaa\Engagement;

use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Validation;

/** The same canonical text rules apply on create, edit and the final write guard. */
final class CommentText
{
    public static function normalize(string $text, int $maximum = 2000): string
    {
        if (preg_match('//u', $text) !== 1 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text) === 1) {
            throw new EngagementException('VALIDATION_FAILED', 422, 'Use valid UTF-8 plain text.');
        }
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/\A[\s\p{Z}]+|[\s\p{Z}]+\z/u', '', $text) ?? '';
        $violations = Validation::createValidator()->validate($text, [new NotBlank(), new Length(min: 1, max: $maximum)]);
        if (strlen($text) > $maximum * 4 || count($violations) !== 0) {
            throw new EngagementException('VALIDATION_FAILED', 422, "Use between 1 and {$maximum} characters.");
        }

        return $text;
    }
}
