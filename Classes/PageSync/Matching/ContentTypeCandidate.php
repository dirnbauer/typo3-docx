<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

use Webconsulting\DocxEditor\PageSync\Schema\ElementShape;

/**
 * A content element type the editor may create at the target page and column.
 */
final readonly class ContentTypeCandidate
{
    /**
     * The types TYPO3 itself defines (EXT:frontend), the safe default for plain content.
     */
    public const array CORE_TYPES = ['header', 'text', 'textpic', 'textmedia', 'image', 'bullets', 'table', 'uploads'];

    public function __construct(
        public string $cType,
        public ElementShape $shape,
    ) {}

    public function isCore(): bool
    {
        return in_array($this->cType, self::CORE_TYPES, true);
    }
}
