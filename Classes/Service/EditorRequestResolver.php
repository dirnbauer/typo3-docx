<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Module\ModuleData;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

/**
 * Reads editor-relevant input from the backend request: which file to open
 * and which editor UI locale to use for the backend user.
 */
final readonly class EditorRequestResolver
{
    /**
     * The combined FAL identifier from module data, or the `file` / `target`
     * query or body parameter (the file list passes `target`).
     */
    public function resolveFileIdentifier(ServerRequestInterface $request): string
    {
        $moduleData = $request->getAttribute('moduleData');
        if ($moduleData instanceof ModuleData) {
            $fromModule = trim((string)$moduleData->get('file', ''));
            if ($fromModule !== '') {
                return $fromModule;
            }
        }

        $query = $request->getQueryParams();
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $fromRequest = $query['file'] ?? $body['file'] ?? $query['target'] ?? $body['target'] ?? '';

        return is_string($fromRequest) ? trim($fromRequest) : '';
    }

    /**
     * eigenpal/docx-editor ships English and German; everything else falls
     * back to English. "default" is TYPO3's English marker.
     */
    public function resolveEditorLocale(ServerRequestInterface $request): string
    {
        $backendUser = $request->getAttribute('backend.user');
        if (!$backendUser instanceof BackendUserAuthentication) {
            return 'en';
        }

        $lang = strtolower(trim((string)($backendUser->user['lang'] ?? '')));
        if ($lang !== '' && $lang !== 'default' && str_starts_with($lang, 'de')) {
            return 'de';
        }

        return 'en';
    }
}
