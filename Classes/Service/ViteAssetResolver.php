<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Resolves the Vite build entry from Resources/Public/Vite/manifest.json.
 */
final class ViteAssetResolver
{
    private const MANIFEST_PATH = 'EXT:docx_editor/Resources/Public/Vite/manifest.json';
    private const ENTRY = 'Build/Sources/docx-editor.js';

    private ?string $publicBase = null;

    public function hasBuild(): bool
    {
        return $this->resolveManifest() !== null;
    }

    public function resolveScript(): ?string
    {
        $manifest = $this->resolveManifest();
        if ($manifest === null) {
            return null;
        }
        $entry = $manifest[self::ENTRY] ?? null;
        if (!is_array($entry) || !is_string($entry['file'] ?? null)) {
            return null;
        }
        return $this->publicUrl($entry['file']);
    }

    public function resolveStylesheet(): ?string
    {
        $manifest = $this->resolveManifest();
        if ($manifest === null) {
            return null;
        }
        $entry = $manifest[self::ENTRY] ?? null;
        if (!is_array($entry) || !is_array($entry['css'] ?? null)) {
            return null;
        }
        $cssFile = $entry['css'][0] ?? null;
        if (!is_string($cssFile)) {
            return null;
        }
        return $this->publicUrl($cssFile);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveManifest(): ?array
    {
        $path = GeneralUtility::getFileAbsFileName(self::MANIFEST_PATH);
        if ($path === '' || !is_file($path)) {
            return null;
        }
        try {
            $data = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        return is_array($data) ? $data : null;
    }

    private function publicUrl(string $relative): string
    {
        $base = $this->publicBase ??= PathUtility::getAbsoluteWebPath(
            GeneralUtility::getFileAbsFileName('EXT:docx_editor/Resources/Public/Vite/'),
        );
        return $base . ltrim($relative, '/');
    }
}
