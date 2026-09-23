<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

/**
 * The page round trip's extension settings (Admin Tools > Settings > docx_editor > pageSync),
 * read once and typed.
 */
final readonly class PageSyncSettings
{
    public const string EXTENSION_KEY = 'docx_editor';

    /** @var array<string, mixed> */
    private array $raw;

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        try {
            $raw = $extensionConfiguration->get(self::EXTENSION_KEY, 'pageSync');
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            $raw = [];
        }
        $this->raw = is_array($raw) ? $raw : [];
    }

    /**
     * FAL folder new pictures go to; "{page}" is replaced by the page uid.
     */
    public function imageFolder(int $pageUid): string
    {
        $folder = $this->string('imageFolder', '1:/user_upload/word/{page}/');

        return str_replace('{page}', (string)$pageUid, $folder);
    }

    public function maxUploadMegabytes(): int
    {
        return max(1, min(200, $this->int('maxUploadMegabytes', 25)));
    }

    /**
     * An EXT: or project path to a .dotx/.docx whose styles exported documents use.
     */
    public function wordTemplate(): string
    {
        return $this->string('wordTemplate', '');
    }

    /**
     * Content element types never proposed for new content ("html" would render text as markup).
     *
     * @return list<string>
     */
    public function excludedContentTypes(): array
    {
        $types = array_map('trim', explode(',', $this->string('excludedContentTypes', 'html')));

        return array_values(array_filter($types, static fn(string $type): bool => $type !== ''));
    }

    public function jevEnabled(): bool
    {
        return $this->bool('jevEnabled', true);
    }

    /**
     * Below this confidence Jev's choice is shown but not taken; the part keeps the best
     * structural candidate and is flagged for review.
     */
    public function jevConfidenceThreshold(): float
    {
        return max(0.0, min(1.0, $this->float('jevConfidenceThreshold', 0.6)));
    }

    /**
     * How many of the best structural candidates Jev chooses between.
     */
    public function jevMaxCandidates(): int
    {
        return max(2, min(12, $this->int('jevMaxCandidates', 5)));
    }

    /**
     * Seconds Jev's answers are cached; -1 uses webcon_jev's own setting.
     */
    public function jevCacheLifetime(): int
    {
        return max(-1, $this->int('jevCacheLifetime', -1));
    }

    private function string(string $key, string $default): string
    {
        $value = $this->raw[$key] ?? null;

        return is_scalar($value) && trim((string)$value) !== '' ? trim((string)$value) : $default;
    }

    private function int(string $key, int $default): int
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (int)$value : $default;
    }

    private function float(string $key, float $default): float
    {
        $value = $this->raw[$key] ?? null;

        return is_numeric($value) ? (float)$value : $default;
    }

    private function bool(string $key, bool $default): bool
    {
        $value = $this->raw[$key] ?? null;
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
