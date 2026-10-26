<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Tests\Unit\PageSync\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\DocxEditor\PageSync\Export\PictureDerivatives;
use Webconsulting\DocxEditor\Tests\Fixtures\PageSync\DocxFixtureBuilder;

final class PictureDerivativesTest extends UnitTestCase
{
    /**
     * @return array<string, array{0: int, 1: int, 2: int, 3: int, 4: array{0: int, 1: int}|null}>
     */
    public static function sizes(): array
    {
        return [
            // Shown text-wide (6.3 in): 945 pixels at 150 ppi.
            'a wide screenshot at 150 ppi' => [1672, 941, 150, 2000, [945, 532]],
            'the same at 220 ppi' => [1672, 941, 220, 2000, [1386, 780]],
            // Shown at 96 ppi, 4.2 in wide: needs 625 pixels at 150 ppi, has fewer.
            'a small picture stays' => [400, 300, 150, 2000, null],
            // Text-wide, 945 pixels wide at 150 ppi — but no edge above 2000.
            'a tall picture is held by the long edge' => [1000, 4000, 150, 2000, [500, 2000]],
            'no resolution: only the long edge' => [3000, 2000, 0, 2000, [2000, 1333]],
            'no limits' => [6000, 4000, 0, 0, null],
            'an unknown size stays' => [0, 0, 150, 2000, null],
        ];
    }

    /**
     * @param array{0: int, 1: int}|null $expected
     */
    #[Test]
    #[DataProvider('sizes')]
    public function aPictureIsEmbeddedAsLargeAsWordShowsIt(int $width, int $height, int $resolution, int $maxEdge, ?array $expected): void
    {
        self::assertSame($expected, PictureDerivatives::targetSize($width, $height, $resolution, $maxEdge));
    }

    /**
     * @return array<string, array{0: string, 1: string|null}>
     */
    public static function webps(): array
    {
        return [
            'lossy' => [self::webp('VP8 ', "\x30\x01\x00\x9d\x01\x2a"), 'jpg'],
            'lossless, opaque' => [self::webp('VP8L', "\x2f" . pack('V', 15 | (7 << 14))), 'jpg'],
            'lossless with transparency' => [self::webp('VP8L', "\x2f" . pack('V', 15 | (7 << 14) | (1 << 28))), 'png'],
            'extended, opaque' => [self::webp('VP8X', "\x00\x00\x00\x00"), 'jpg'],
            'extended with transparency' => [self::webp('VP8X', "\x10\x00\x00\x00"), 'png'],
            'animated' => [self::webp('VP8X', "\x12\x00\x00\x00"), null],
            'no WebP' => [str_pad("\x89PNG\r\n\x1a\n", 40, "\x00"), null],
            'cut off' => ['RIFF', null],
        ];
    }

    #[Test]
    #[DataProvider('webps')]
    public function aWebpIsEmbeddedInAFormatWordKeeps(string $bytes, ?string $expected): void
    {
        self::assertSame($expected, PictureDerivatives::webpFormat($bytes));
    }

    #[Test]
    public function theEmbeddedBytesTellTheirFormat(): void
    {
        $image = imagecreatetruecolor(4, 2);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image);
        $jpeg = (string)ob_get_clean();

        self::assertSame('image/jpeg', PictureDerivatives::mimeType($jpeg, 'image/webp'), 'A JPEG copy of a WebP');
        self::assertSame('image/png', PictureDerivatives::mimeType(DocxFixtureBuilder::photo(4, 2), 'image/png'));
        self::assertSame('image/webp', PictureDerivatives::mimeType(self::webp('VP8 ', ''), 'image/webp'), 'The WebP as it is');
        self::assertSame('image/gif', PictureDerivatives::mimeType('', 'image/gif'));
    }

    private static function webp(string $chunk, string $data): string
    {
        $data = str_pad($data, 18, "\x00");
        $body = 'WEBP' . $chunk . pack('V', strlen($data)) . $data;

        return 'RIFF' . pack('V', strlen($body)) . $body;
    }
}
