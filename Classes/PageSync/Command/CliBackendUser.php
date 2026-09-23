<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Command;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use Webconsulting\DocxEditor\PageSync\Exception\PageSyncException;

/**
 * The backend user the page commands act as: TYPO3's CLI user (an admin), in the workspace the
 * command was given.
 */
final class CliBackendUser
{
    public static function initialize(int $workspaceId): BackendUserAuthentication
    {
        Bootstrap::initializeBackendAuthentication();
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication) {
            throw new \RuntimeException('No backend user on the command line.', 1790000001);
        }
        if ($workspaceId !== (int)$user->workspace && !$user->setTemporaryWorkspace($workspaceId)) {
            throw new PageSyncException('error.workspaceNotFound', 404, [$workspaceId]);
        }

        return $user;
    }
}
