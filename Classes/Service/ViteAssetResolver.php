<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Resolves the Vite build entry from Resources/Public/Vite/manifest.json.
 */
final class ViteAssetResolver
{
    private const MANIFEST_PATH = 'EXT:docx_editor/Resources/Public/Vite/manifest.json';
    private const VITE_PUBLIC = 'EXT:docx_editor/Resources/Public/Vite/';
    private const ENTRY = 'Build/Sources/docx-editor.js';

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
        return $this->extensionResource($entry['file']);
    }

    public function resolveStylesheet(): ?string
    {
        $manifest = $this->resolveManifest();
        if ($manifest === null) {
            return null;
        }
        $entry = $manifest[self::ENTRY] ?? null;
        if (is_array($entry) && is_array($entry['css'] ?? null)) {
            $cssFile = $entry['css'][0] ?? null;
            if (is_string($cssFile)) {
                return $this->extensionResource($cssFile);
            }
        }

        $styleChunk = $manifest['style.css'] ?? null;
        if (is_array($styleChunk) && is_string($styleChunk['file'] ?? null)) {
            return $this->extensionResource($styleChunk['file']);
        }

        return null;
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

    private function extensionResource(string $relative): string
    {
        return self::VITE_PUBLIC . ltrim($relative, '/');
    }
}
