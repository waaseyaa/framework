<?php

declare(strict_types=1);

namespace Waaseyaa\PageBuilder;

/**
 * One node in a decoded page's block tree.
 *
 * Blocks form a tree so layout intent survives decoding: a structural block
 * (band, grid, card, column) carries child blocks and layout data (column
 * count, background variant, card flag), while a leaf block (heading, text,
 * image, button, list, icon_box, gallery, html) carries content. A renderer
 * walks the tree to rebuild the original grid/card layout; a consumer that only
 * wants output reads {@see DecodedPage::$html}.
 *
 * `type` is a small builder-agnostic vocabulary (see the constants). Decoders
 * map their native element/widget names onto it. `data` is type-specific,
 * `children` are nested blocks (empty for leaves), and `html` is the rendered
 * HTML for leaf blocks (structural blocks render from their children).
 *
 * @api
 */
final readonly class Block
{
    // Structural.
    public const string BAND = 'band';       // a full-width section/row; data: variant, layout, columns
    public const string GRID = 'grid';       // an explicit responsive grid of children
    public const string COLUMN = 'column';   // a single column / sub-container
    public const string CARD = 'card';       // a styled box (white rounded card)

    // Leaf.
    public const string HEADING = 'heading';
    public const string TEXT = 'text';
    public const string IMAGE = 'image';
    public const string BUTTON = 'button';
    public const string LIST = 'list';
    public const string ICON_BOX = 'icon_box'; // title + description (+ optional link)
    public const string GALLERY = 'gallery';   // a set of images
    public const string HTML = 'html';
    public const string COMPONENT = 'component'; // raw HTML + owned, scoped CSS (data: scope, css)

    /**
     * @param string $type One of the type constants.
     * @param array<string, mixed> $data Type-specific payload (e.g. `['level' => 2, 'text' => '...']`, or `['variant' => 'gradient', 'layout' => 'grid', 'columns' => 3]`).
     * @param string $html Rendered HTML for leaf blocks; empty for structural blocks (rendered from {@see $children}).
     * @param list<Block> $children Nested blocks (empty for leaves).
     */
    public function __construct(
        public string $type,
        public array $data = [],
        public string $html = '',
        public array $children = [],
    ) {}
}
