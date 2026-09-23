<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Exception;

use TYPO3\CMS\Core\Localization\LanguageService;

/**
 * Domain error whose code is the HTTP status the JSON API answers with and
 * whose message is a key of the docx_editor.messages domain, so the editor
 * page and the API can show it in the backend user's language.
 */
final class DocxEditorException extends \RuntimeException
{
    private const string DOMAIN = 'docx_editor.messages';

    /**
     * @param string $labelKey e.g. "error.fileNotFound"
     * @param list<string> $arguments sprintf() arguments of the label
     */
    public function __construct(
        public readonly string $labelKey,
        int $httpStatus,
        public readonly array $arguments = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($labelKey, $httpStatus, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->code >= 400 && $this->code <= 599 ? $this->code : 500;
    }

    /**
     * The message in the language of the given language service; the label
     * key where no translation exists.
     */
    public function localizedMessage(?LanguageService $languageService): string
    {
        $label = $languageService?->sL(self::DOMAIN . ':' . $this->labelKey) ?? '';
        if ($label === '') {
            return $this->labelKey;
        }

        return $this->arguments === [] ? $label : vsprintf($label, $this->arguments);
    }
}
