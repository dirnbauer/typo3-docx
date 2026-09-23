<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;

/**
 * Read access to the parts of an uploaded .docx package, with the limits a hostile file needs
 * to be stopped by: archive size, part count, per-part and total unpacked size, compression
 * ratio, and part names that try to leave the package.
 *
 * Parts are only ever read into memory; nothing is extracted to disk.
 */
final class DocxArchive
{
    /** Parts below this size may compress arbitrarily well (a repetitive XML part is fine). */
    private const int RATIO_CHECK_THRESHOLD = 1024 * 1024;

    /** @var array<string, string> */
    private array $cache = [];

    private int $bytesRead = 0;

    /**
     * @param array<string, int> $index Lower-cased part name => zip index (OOXML part names are case-insensitive)
     * @param array<string, string> $names Lower-cased part name => name as stored
     */
    private function __construct(
        private readonly \ZipArchive $zip,
        private readonly string $temporaryFile,
        private readonly ArchiveLimits $limits,
        private readonly array $index,
        private readonly array $names,
    ) {}

    public function __destruct()
    {
        $this->zip->close();
        if (is_file($this->temporaryFile)) {
            unlink($this->temporaryFile);
        }
    }

    public static function fromBinary(string $binary, ArchiveLimits $limits = new ArchiveLimits()): self
    {
        if (strlen($binary) > $limits->maxArchiveBytes) {
            throw new PageSyncException('error.fileTooLarge', 413, [(int)ceil($limits->maxArchiveBytes / 1024 / 1024)]);
        }
        if (!str_starts_with($binary, "PK\x03\x04")) {
            throw new PageSyncException('error.notADocx', 415);
        }

        $temporaryFile = GeneralUtility::tempnam('docx_pagesync_');
        if (file_put_contents($temporaryFile, $binary) === false) {
            throw new PageSyncException('error.unreadable', 500);
        }

        $zip = new \ZipArchive();
        if ($zip->open($temporaryFile, \ZipArchive::RDONLY) !== true) {
            unlink($temporaryFile);
            throw new PageSyncException('error.notADocx', 415);
        }

        try {
            [$index, $names] = self::inspect($zip, $limits);
        } catch (PageSyncException $exception) {
            $zip->close();
            unlink($temporaryFile);
            throw $exception;
        }

        return new self($zip, $temporaryFile, $limits, $index, $names);
    }

    /**
     * @return array{0: array<string, int>, 1: array<string, string>}
     */
    private static function inspect(\ZipArchive $zip, ArchiveLimits $limits): array
    {
        if ($zip->numFiles > $limits->maxEntries) {
            throw new PageSyncException('error.tooManyParts', 422, [$limits->maxEntries]);
        }

        $index = [];
        $names = [];
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat === false) {
                throw new PageSyncException('error.notADocx', 415);
            }
            $name = $stat['name'];
            if (str_ends_with($name, '/')) {
                continue;
            }
            if (!self::isSafePartName($name)) {
                throw new PageSyncException('error.unsafePartName', 422, [$name]);
            }
            $size = $stat['size'];
            $compressed = $stat['comp_size'];
            if ($size > $limits->maxEntryBytes) {
                throw new PageSyncException('error.partTooLarge', 422, [$name]);
            }
            if ($size > self::RATIO_CHECK_THRESHOLD && ($compressed <= 0 || $size / $compressed > $limits->maxRatio)) {
                throw new PageSyncException('error.zipBomb', 422, [$name]);
            }
            $total += $size;
            if ($total > $limits->maxTotalBytes) {
                throw new PageSyncException('error.zipBomb', 422, [$name]);
            }
            $key = strtolower($name);
            $index[$key] = $i;
            $names[$key] = $name;
        }

        return [$index, $names];
    }

    private static function isSafePartName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || str_contains($name, '\\') || str_starts_with($name, '/')) {
            return false;
        }
        foreach (explode('/', $name) as $segment) {
            if ($segment === '..' || $segment === '.') {
                return false;
            }
        }

        return true;
    }

    public function has(string $partName): bool
    {
        return isset($this->index[strtolower(ltrim($partName, '/'))]);
    }

    /**
     * @return list<string> Part names as stored in the package
     */
    public function partNames(): array
    {
        return array_values($this->names);
    }

    public function read(string $partName): string
    {
        $key = strtolower(ltrim($partName, '/'));
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }
        $index = $this->index[$key] ?? null;
        if ($index === null) {
            throw new PageSyncException('error.missingPart', 422, [$partName]);
        }

        $stat = $this->zip->statIndex($index);
        $declared = $stat === false ? 0 : $stat['size'];
        $contents = $declared === 0 ? '' : $this->zip->getFromIndex($index, $declared);
        if ($contents === false || strlen($contents) !== $declared) {
            throw new PageSyncException('error.brokenPart', 422, [$partName]);
        }
        $this->bytesRead += $declared;
        if ($this->bytesRead > $this->limits->maxTotalBytes) {
            throw new PageSyncException('error.zipBomb', 422, [$partName]);
        }

        // Pictures are read once and handed on; keep only the XML parts that are consulted repeatedly.
        if (str_ends_with($key, '.xml') || str_ends_with($key, '.rels')) {
            $this->cache[$key] = $contents;
        }

        return $contents;
    }

    public function xml(string $partName): \DOMDocument
    {
        return SafeXml::load($this->read($partName), $partName);
    }

    /**
     * The relationships of a part ("" for the package itself), keyed by id.
     *
     * @return array<string, Relationship>
     */
    public function relationships(string $sourcePart): array
    {
        $sourcePart = ltrim($sourcePart, '/');
        $directory = $sourcePart === '' ? '' : (str_contains($sourcePart, '/') ? substr($sourcePart, 0, (int)strrpos($sourcePart, '/')) : '');
        $relsPart = ($directory === '' ? '' : $directory . '/') . '_rels/' . ($sourcePart === '' ? '' : basename($sourcePart)) . '.rels';
        if (!$this->has($relsPart)) {
            return [];
        }

        $relationships = [];
        $document = $this->xml($relsPart);
        foreach ($document->getElementsByTagNameNS(Ns::PACKAGE_RELATIONSHIPS, 'Relationship') as $element) {
            $id = $element->getAttribute('Id');
            $external = strcasecmp($element->getAttribute('TargetMode'), 'External') === 0;
            $target = $element->getAttribute('Target');
            if (!$external) {
                $target = self::resolvePartName($directory, $target);
            }
            $relationships[$id] = new Relationship($id, $element->getAttribute('Type'), $target, $external);
        }

        return $relationships;
    }

    /**
     * The main document part, found through the package relationships as the spec asks — not
     * assumed to be word/document.xml.
     */
    public function mainDocumentPart(): string
    {
        foreach ($this->relationships('') as $relationship) {
            if (!$relationship->external
                && ($relationship->type === Ns::REL_OFFICE_DOCUMENT || $relationship->type === Ns::REL_OFFICE_DOCUMENT_STRICT)
                && $this->has($relationship->target)
            ) {
                return $relationship->target;
            }
        }
        if ($this->has('word/document.xml')) {
            return 'word/document.xml';
        }

        throw new PageSyncException('error.notADocx', 415);
    }

    public function firstRelationshipOfType(string $sourcePart, string $type): ?Relationship
    {
        return array_find(
            $this->relationships($sourcePart),
            static fn(Relationship $relationship): bool => $relationship->type === $type && !$relationship->external,
        );
    }

    /**
     * Resolves a relative relationship target ("media/image1.png", "../customXml/item1.xml")
     * against the directory of its source part.
     */
    public static function resolvePartName(string $baseDirectory, string $target): string
    {
        if (str_starts_with($target, '/')) {
            $path = ltrim($target, '/');
        } else {
            $path = ($baseDirectory === '' ? '' : $baseDirectory . '/') . $target;
        }
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }
}
