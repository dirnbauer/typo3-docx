<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Plan;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;

/**
 * Keeps an uploaded Word document between the preview and the apply, for the user who
 * uploaded it and for a day at most. Nothing but the document and what the preview decided is
 * stored; the plan itself is rebuilt from the document when it is applied.
 */
final readonly class PlanStore
{
    private const int LIFETIME = 86400;

    /**
     * @param array<string, mixed> $meta
     */
    public function store(string $binary, array $meta, int $userId): string
    {
        $this->collectGarbage();
        $id = bin2hex(random_bytes(16));
        $directory = $this->directory();
        if (file_put_contents($directory . $id . '.docx', $binary) === false
            || file_put_contents($directory . $id . '.json', json_encode(['user' => $userId, 'created' => time(), 'meta' => $meta], JSON_THROW_ON_ERROR)) === false
        ) {
            throw new PageSyncException('error.storeFailed', 500);
        }

        return $id;
    }

    /**
     * @return array{binary: string, meta: array<string, mixed>}
     */
    public function load(string $id, int $userId): array
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            throw new PageSyncException('error.planNotFound', 404);
        }
        $directory = $this->directory();
        $json = is_file($directory . $id . '.json') ? file_get_contents($directory . $id . '.json') : false;
        $binary = is_file($directory . $id . '.docx') ? file_get_contents($directory . $id . '.docx') : false;
        if ($json === false || $binary === false) {
            throw new PageSyncException('error.planNotFound', 404);
        }
        $stored = json_decode($json, true);
        if (!is_array($stored) || ($stored['user'] ?? null) !== $userId || !is_array($stored['meta'] ?? null)) {
            throw new PageSyncException('error.planNotFound', 404);
        }
        if ((int)($stored['created'] ?? 0) < time() - self::LIFETIME) {
            $this->delete($id);
            throw new PageSyncException('error.planNotFound', 404);
        }
        /** @var array<string, mixed> $meta */
        $meta = $stored['meta'];

        return ['binary' => $binary, 'meta' => $meta];
    }

    public function delete(string $id): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $id) !== 1) {
            return;
        }
        foreach (['.docx', '.json'] as $suffix) {
            $file = $this->directory() . $id . $suffix;
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    private function collectGarbage(): void
    {
        foreach (glob($this->directory() . '*.json') ?: [] as $file) {
            if (filemtime($file) < time() - self::LIFETIME) {
                $this->delete(basename($file, '.json'));
            }
        }
    }

    private function directory(): string
    {
        $directory = Environment::getVarPath() . '/transient/docx_editor/page-sync/';
        if (!is_dir($directory)) {
            GeneralUtility::mkdir_deep($directory);
        }

        return $directory;
    }
}
