<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Exception;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * A page-sync failure the editor or CLI user can act on. The message is a key of the
 * docx_editor.pagesync label domain; the code is the HTTP status an API answers with.
 */
final class PageSyncException extends \RuntimeException
{
    private const string DOMAIN = 'docx_editor.pagesync';

    /**
     * @param string $labelKey e.g. "error.notADocx"
     * @param list<string|int> $arguments sprintf() arguments of the label
     */
    public function __construct(
        public readonly string $labelKey,
        int $httpStatus = 400,
        public readonly array $arguments = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($labelKey . ($arguments !== [] ? ' (' . implode(', ', $arguments) . ')' : ''), $httpStatus, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->code >= 400 && $this->code <= 599 ? $this->code : 500;
    }

    public function localizedMessage(?LanguageService $languageService): string
    {
        $label = $languageService?->sL(self::DOMAIN . ':' . $this->labelKey) ?? '';
        if ($label === '') {
            return $this->getMessage();
        }

        return $this->arguments === [] ? $label : vsprintf($label, $this->arguments);
    }
}
