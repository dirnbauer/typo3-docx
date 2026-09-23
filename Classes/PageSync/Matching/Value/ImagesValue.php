<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Value;

use Webconsulting\DocxEditor\PageSync\Document\Figure;

/**
 * Pictures for a file field, with alt text and caption.
 */
final readonly class ImagesValue implements FieldValue
{
    /**
     * @param list<Figure> $figures
     */
    public function __construct(
        public array $figures,
    ) {}

    #[\Override]
    public function preview(): string
    {
        return implode(', ', array_map(
            static fn(Figure $figure): string => $figure->image->data->fileName . ($figure->image->alternative !== '' ? ' (' . $figure->image->alternative . ')' : ''),
            $this->figures,
        ));
    }
}
