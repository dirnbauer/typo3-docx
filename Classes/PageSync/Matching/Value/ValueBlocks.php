<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching\Value;

use Webconsulting\DocxEditor\PageSync\Document\Block;
use Webconsulting\DocxEditor\PageSync\Document\Link;
use Webconsulting\DocxEditor\PageSync\Document\Paragraph;
use Webconsulting\DocxEditor\PageSync\Document\Text;

/**
 * A placed value as blocks again — so content that reached a field through the matcher (an
 * element recognised without its content control) is compared and written through the same
 * field codec as content that arrived in the field's own control.
 */
final class ValueBlocks
{
    /**
     * @return list<Block>
     */
    public static function of(FieldValue $value): array
    {
        return match (true) {
            $value instanceof BlocksValue => $value->blocks,
            $value instanceof TextValue => trim($value->text) === '' ? [] : [new Paragraph([new Text($value->text)])],
            $value instanceof ImagesValue => $value->figures,
            $value instanceof TableValue => [$value->table],
            $value instanceof LinkValue => [new Paragraph([new Link($value->href, [new Text($value->href)])])],
            default => [],
        };
    }
}
