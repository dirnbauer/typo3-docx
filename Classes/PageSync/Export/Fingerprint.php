<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Export;

/**
 * A compact sketch of a text's wording (bottom-k MinHash over word trigrams), to recognise an
 * element whose content control was removed: its text is still mostly the same words.
 */
final class Fingerprint
{
    private const int SIZE = 48;

    public static function of(string $text): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? $words : [];
        if ($words === []) {
            return '';
        }
        $shingles = [];
        $count = count($words);
        for ($i = 0; $i < max(1, $count - 2); $i++) {
            $shingles[] = hash('crc32b', implode(' ', array_slice($words, $i, 3)));
        }
        $shingles = array_values(array_unique($shingles));
        sort($shingles);

        return implode('.', array_slice($shingles, 0, self::SIZE));
    }

    /**
     * Estimated Jaccard similarity of the two texts' wording, 0 to 1.
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' || $b === '') {
            return 0.0;
        }
        $first = explode('.', $a);
        $second = explode('.', $b);
        $union = array_values(array_unique([...$first, ...$second]));
        sort($union);
        $union = array_slice($union, 0, self::SIZE);
        if ($union === []) {
            return 0.0;
        }
        $both = array_intersect($union, $first, $second);

        return count($both) / count($union);
    }
}
