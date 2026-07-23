<?php

declare(strict_types=1);

namespace Waaseyaa\SSR\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\SSR\PageComposition\EntityPageField;
use Waaseyaa\SSR\PageComposition\EntityPageRenderPayload;

#[CoversClass(EntityPageField::class)]
#[CoversClass(EntityPageRenderPayload::class)]
final class EntityPageRenderPayloadTest extends TestCase
{
    #[Test]
    public function exposes_only_formatted_authorized_field_fragments(): void
    {
        $body = new EntityPageField('body', 'text_long', '<p>Safe body</p>');
        $payload = new EntityPageRenderPayload(
            title: 'Safe title',
            requestPath: '/news/safe-title',
            entityType: 'node',
            bundle: 'article',
            viewMode: 'full',
            langcode: 'en',
            fields: ['body' => $body],
            schemaOrgJsonLd: '<script type="application/ld+json">{}</script>',
            bodyCompositionHtml: '<div class="pb-band"><p>Safe body</p></div>',
        );

        self::assertSame('Safe title', $payload->title);
        self::assertSame('/news/safe-title', $payload->requestPath);
        self::assertSame('node', $payload->entityType);
        self::assertSame('article', $payload->bundle);
        self::assertSame('full', $payload->viewMode);
        self::assertSame('en', $payload->langcode);
        self::assertSame($body, $payload->field('body'));
        self::assertNull($payload->field('secret'));
        self::assertSame('<p>Safe body</p>', $payload->bodyHtml());
        self::assertSame('<script type="application/ld+json">{}</script>', $payload->schemaOrgJsonLd);
        self::assertSame('<div class="pb-band"><p>Safe body</p></div>', $payload->bodyCompositionHtml);

        $fieldProperties = array_keys(get_object_vars($body));
        self::assertSame(['name', 'type', 'formatted'], $fieldProperties);
        self::assertNotContains('raw', $fieldProperties);

        $payloadProperties = array_keys(get_object_vars($payload));
        self::assertNotContains('entity', $payloadProperties);
        self::assertNotContains('account', $payloadProperties);
        self::assertNotContains('raw', $payloadProperties);
        self::assertNotContains('template', $payloadProperties);
    }

    #[Test]
    public function body_html_is_empty_when_the_authorized_body_field_is_absent(): void
    {
        $payload = new EntityPageRenderPayload(
            title: 'No body',
            requestPath: '/no-body',
            entityType: 'node',
            bundle: 'page',
            viewMode: 'full',
            langcode: 'en',
            fields: [],
            schemaOrgJsonLd: '',
            bodyCompositionHtml: '',
        );

        self::assertSame('', $payload->bodyHtml());
    }
}
