<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Exception;

/**
 * Domain error whose code is the HTTP status the JSON API answers with.
 */
final class DocxEditorException extends \RuntimeException
{
    public function __construct(string $message, int $httpStatus, ?\Throwable $previous = null)
    {
        parent::__construct($message, $httpStatus, $previous);
    }

    public function getStatusCode(): int
    {
        return $this->code >= 400 && $this->code <= 599 ? $this->code : 500;
    }
}
