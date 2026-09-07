<?php

declare(strict_types=1);

namespace Waaseyaa\Tests\Integration\Generation\Fixtures;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;

/**
 * A *registered* entity type, which is the whole point of this fixture.
 *
 * Read levels resolve differently for registered and unregistered types: an
 * unregistered fixture defaults every undeclared field to
 * `FieldReadLevel::Public`, which is why an anonymous-class projector test can
 * pass while the same projector indexes nothing in a real application. This
 * class carries `#[ContentEntityType]`, so it takes the registered branch and
 * the read levels below are the ones a shipped application actually gets.
 *
 * The three non-label fields deliberately span the classification space:
 * `body` is explicitly Public, `internal_note` declares no level (registered
 * default: Internal), and `restricted_note` is Protected, which is
 * deny-unless-granted — it stays unreadable for a principal no policy has
 * granted.
 */
#[ContentEntityType(id: 'scaffold_story', label: 'Scaffolded Story')]
#[ContentEntityKeys(label: 'title')]
final class ScaffoldedStory extends ContentEntityBase
{
    #[Field(type: 'string', label: 'Title', read: FieldReadLevel::Public)]
    public string $title = '';

    #[Field(type: 'text', label: 'Body', read: FieldReadLevel::Public)]
    public string $body = '';

    #[Field(type: 'text', label: 'Internal note')]
    public string $internal_note = '';

    #[Field(type: 'text', label: 'Restricted note', read: FieldReadLevel::Protected)]
    public string $restricted_note = '';
}
