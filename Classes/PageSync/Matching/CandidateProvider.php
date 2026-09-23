<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Matching;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShape;
use Webconsulting\DocxEditor\PageSync\Schema\ElementShapeFactory;
use Webconsulting\DocxEditor\PageSync\Schema\FieldInfo;

/**
 * The content element types a backend user may create at a page and column — the same set the
 * "New content element" wizard offers there:
 *
 * - the CType items of tt_content,
 * - minus wizard groups and elements removed by page TSconfig
 *   (mod.wizards.newContentElement.wizardItems[.<group>].removeItems),
 * - minus TCEFORM.tt_content.CType.removeItems, and only keepItems where set,
 * - within the column's allowedContentTypes / disallowedContentTypes of the backend layout,
 * - and, where CType has an authMode, only what the user's groups allow.
 *
 * Fields the user may not edit (exclude fields without non_exclude_fields permission) are taken
 * out of each type's shape, so no content is planned into a field the DataHandler would drop.
 */
final readonly class CandidateProvider
{
    public function __construct(
        private ElementShapeFactory $shapes,
        private BackendLayoutView $backendLayoutView,
        private TcaSchemaFactory $schemaFactory,
    ) {}

    /**
     * @return array<string, ContentTypeCandidate> Keyed by CType
     */
    public function forColumn(int $pageUid, int $colPos, BackendUserAuthentication $user): array
    {
        $pageTsConfig = BackendUtility::getPagesTSconfig($pageUid);
        $wizardItems = $pageTsConfig['mod.']['wizards.']['newContentElement.']['wizardItems.'] ?? [];
        $wizardItems = is_array($wizardItems) ? $wizardItems : [];
        $removedGroups = self::list($wizardItems['removeItems'] ?? '');
        $typeConfiguration = $pageTsConfig['TCEFORM.']['tt_content.']['CType.'] ?? [];
        $typeConfiguration = is_array($typeConfiguration) ? $typeConfiguration : [];
        $removeItems = self::list($typeConfiguration['removeItems'] ?? '');
        $keepItems = self::list($typeConfiguration['keepItems'] ?? '');

        $layout = $this->backendLayoutView->getBackendLayoutForPage($pageUid);
        $column = $this->backendLayoutView->getColPosConfigurationForPage($layout, $colPos, $pageUid);
        $allowed = self::list($column['allowedContentTypes'] ?? '');
        $disallowed = self::list($column['disallowedContentTypes'] ?? '');

        $authMode = false;
        if ($this->schemaFactory->has('tt_content') && $this->schemaFactory->get('tt_content')->hasField('CType')) {
            $authMode = (bool)($this->schemaFactory->get('tt_content')->getField('CType')->getConfiguration()['authMode'] ?? false);
        }

        $candidates = [];
        foreach ($this->shapes->contentTypes() as $cType => $item) {
            $group = $item['group'] === 'common' ? 'default' : $item['group'];
            if (in_array($group, $removedGroups, true)) {
                continue;
            }
            $groupConfiguration = $wizardItems[$group . '.'] ?? [];
            if (is_array($groupConfiguration) && in_array($cType, self::list($groupConfiguration['removeItems'] ?? ''), true)) {
                continue;
            }
            if (in_array($cType, $removeItems, true) || ($keepItems !== [] && !in_array($cType, $keepItems, true))) {
                continue;
            }
            if (($allowed !== [] && !in_array($cType, $allowed, true)) || in_array($cType, $disallowed, true)) {
                continue;
            }
            if ($authMode && !$user->checkAuthMode('tt_content', 'CType', $cType)) {
                continue;
            }
            $shape = $this->shapes->forContentType($cType);
            if ($shape === null) {
                continue;
            }
            $shape = self::editableBy($user, $shape);
            if (!$shape->hasContentFields()) {
                continue;
            }
            $candidates[$cType] = new ContentTypeCandidate($cType, $shape);
        }

        return $candidates;
    }

    private static function editableBy(BackendUserAuthentication $user, ElementShape $shape): ElementShape
    {
        if ($user->isAdmin()) {
            return $shape;
        }

        return $shape->filtered(
            static fn(FieldInfo $field): bool => !$field->accessControlled || $user->check('non_exclude_fields', $field->table . ':' . $field->name),
        );
    }

    /**
     * @return list<string>
     */
    private static function list(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(static fn(mixed $item): string => is_scalar($item) ? trim((string)$item) : '', $value)));
        }

        return is_scalar($value) ? GeneralUtility::trimExplode(',', (string)$value, true) : [];
    }
}
