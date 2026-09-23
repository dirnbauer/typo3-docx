<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Schema;

use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\Field\FieldTranslationBehaviour;
use TYPO3\CMS\Core\Schema\Field\FieldTypeInterface;
use TYPO3\CMS\Core\Schema\Field\FileFieldType;
use TYPO3\CMS\Core\Schema\Field\InlineFieldType;
use TYPO3\CMS\Core\Schema\Field\InputFieldType;
use TYPO3\CMS\Core\Schema\Field\LinkFieldType;
use TYPO3\CMS\Core\Schema\Field\TextFieldType;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;

/**
 * Builds element shapes from TYPO3's Schema API: the fields a record type shows in its backend
 * form (showitem, palettes and columnsOverrides already applied), classified by kind and role,
 * with the child shapes of its collections.
 */
final class ElementShapeFactory
{
    /**
     * Fields every record has and nobody edits as content.
     */
    private const array SYSTEM_FIELDS = [
        'uid', 'pid', 'CType', 'colPos', 'sys_language_uid', 'l18n_parent', 'l10n_parent', 'l10n_source',
        'l18n_diffsource', 'l10n_diffsource', 'l10n_state', 'hidden', 'starttime', 'endtime', 'fe_group',
        'editlock', 'categories', 'rowDescription', 'header_link', 'header_layout', 'header_position',
        'sectionIndex', 'linkToTop', 'date', 'layout', 'frame_class', 'space_before_class', 'space_after_class',
        'tx_container_parent', 'foreign_table_parent_uid', 'tablenames', 'fieldname', 'sorting', 'crdate',
        'tstamp', 'deleted', 'target', 'selected_categories', 'category_field', 'recursive', 'records',
        'pages', 'file_collections', 'pi_flexform', 'list_type', 'uid_local', 'uid_foreign',
    ];

    private const int MAX_DEPTH = 3;

    /** @var array<string, ElementShape|null> */
    private array $shapes = [];

    /** @var array<string, array{label: string, description: string, group: string, icon: string}>|null */
    private ?array $contentTypes = null;

    private ?LanguageService $english = null;

    public function __construct(
        private readonly TcaSchemaFactory $schemaFactory,
        private readonly FieldRoleClassifier $classifier,
        private readonly LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * The shape of a content element type, or null for a type tt_content does not define.
     */
    public function forContentType(string $cType): ?ElementShape
    {
        $key = 'tt_content:' . $cType;
        if (array_key_exists($key, $this->shapes)) {
            return $this->shapes[$key];
        }
        $item = $this->contentTypes()[$cType] ?? null;
        if ($item === null || !$this->schemaFactory->has('tt_content')) {
            return $this->shapes[$key] = null;
        }
        $schema = $this->schemaFactory->get('tt_content');
        if (!$schema->hasSubSchema($cType)) {
            return $this->shapes[$key] = null;
        }

        return $this->shapes[$key] = $this->build(
            'tt_content',
            $cType,
            $schema->getSubSchema($cType),
            $item['label'],
            $item['description'],
            $item['group'],
            $item['icon'],
            0,
        );
    }

    /**
     * The shape of any other table, e.g. the child table of a collection.
     */
    public function forTable(string $table, int $depth = 0): ?ElementShape
    {
        $key = $table . ':';
        if (array_key_exists($key, $this->shapes)) {
            return $this->shapes[$key];
        }
        if (!$this->schemaFactory->has($table) || $depth > self::MAX_DEPTH) {
            return null;
        }
        $schema = $this->schemaFactory->get($table);
        if ($schema->supportsSubSchema()) {
            foreach (['1', '0'] as $defaultType) {
                if ($schema->hasSubSchema($defaultType)) {
                    $schema = $schema->getSubSchema($defaultType);
                    break;
                }
            }
        }
        $title = (string)($schema->getRawConfiguration()['title'] ?? $table);

        return $this->shapes[$key] = $this->build($table, '', $schema, $title, '', '', '', $depth);
    }

    /**
     * Every content element type tt_content defines, with its wizard label, description, group and icon.
     *
     * @return array<string, array{label: string, description: string, group: string, icon: string}>
     */
    public function contentTypes(): array
    {
        if ($this->contentTypes !== null) {
            return $this->contentTypes;
        }
        $this->contentTypes = [];
        if (!$this->schemaFactory->has('tt_content')) {
            return $this->contentTypes;
        }
        $schema = $this->schemaFactory->get('tt_content');
        $typeField = $schema->supportsSubSchema() ? $schema->getSubSchemaTypeInformation()->getFieldName() : 'CType';
        if (!$schema->hasField($typeField)) {
            return $this->contentTypes;
        }
        $items = $schema->getField($typeField)->getConfiguration()['items'] ?? [];
        foreach (is_array($items) ? $items : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $value = is_scalar($item['value'] ?? null) ? (string)$item['value'] : '';
            if ($value === '' || $value === '--div--') {
                continue;
            }
            $description = $item['description'] ?? '';
            $this->contentTypes[$value] = [
                'label' => is_string($item['label'] ?? null) ? $item['label'] : $value,
                'description' => is_string($description) ? $description : (is_array($description) && is_string($description['description'] ?? null) ? $description['description'] : ''),
                'group' => is_string($item['group'] ?? null) ? $item['group'] : '',
                'icon' => is_string($item['icon'] ?? null) ? $item['icon'] : '',
            ];
        }

        return $this->contentTypes;
    }

    /**
     * Resolves a label in English, the language the role rules are written in.
     */
    public function englishLabel(string $label): string
    {
        if ($label === '') {
            return '';
        }
        $this->english ??= $this->languageServiceFactory->create('en');
        $resolved = $this->english->sL($label);

        return $resolved !== '' ? $resolved : $label;
    }

    private function build(string $table, string $type, TcaSchema $schema, string $label, string $description, string $group, string $icon, int $depth): ElementShape
    {
        $fields = [];
        $children = [];
        foreach ($schema->getFields() as $field) {
            $info = $this->fieldInfo($table, $type, $field);
            if ($info === null) {
                continue;
            }
            if ($info->kind === FieldKind::Collection) {
                $child = $info->childTable !== '' ? $this->forTable($info->childTable, $depth + 1) : null;
                if ($child === null || !$child->hasContentFields()) {
                    continue;
                }
                $children[$info->name] = $child;
            }
            $fields[] = $info;
        }

        return new ElementShape(
            table: $table,
            type: $type,
            label: $label,
            description: $description,
            group: $group,
            icon: $icon,
            fields: $this->classifier->resolveInContext($fields),
            children: $children,
        );
    }

    private function fieldInfo(string $table, string $type, FieldTypeInterface $field): ?FieldInfo
    {
        $name = $field->getName();
        if (in_array($name, self::SYSTEM_FIELDS, true) || str_starts_with($name, 't3ver_')) {
            return null;
        }
        $configuration = $field->getConfiguration();
        $kind = match (true) {
            $field instanceof TextFieldType => $field->isRichText() ? FieldKind::RichText : FieldKind::Text,
            $field instanceof InputFieldType => FieldKind::Input,
            $field instanceof FileFieldType => FieldKind::File,
            $field instanceof LinkFieldType => FieldKind::Link,
            $field instanceof InlineFieldType => FieldKind::Collection,
            default => FieldKind::Other,
        };
        if ($kind === FieldKind::Other) {
            return null;
        }
        $childTable = $kind === FieldKind::Collection && is_string($configuration['foreign_table'] ?? null) ? $configuration['foreign_table'] : '';
        if ($kind === FieldKind::Collection && ($childTable === '' || $childTable === 'sys_file_reference')) {
            return null;
        }
        $label = $field->getLabel();
        $maxItems = (int)($configuration['maxitems'] ?? 0);
        $minItems = (int)($configuration['minitems'] ?? 0);

        return new FieldInfo(
            table: $table,
            name: $name,
            label: $label,
            kind: $kind,
            role: $this->classifier->classify($table, $type, $name, $this->englishLabel($label), $kind),
            required: $field->isRequired() || $minItems > 0,
            maxItems: max(0, $maxItems),
            minItems: max(0, $minItems),
            allowedFileExtensions: $field instanceof FileFieldType ? array_values(array_map('strval', $field->getAllowedFileExtensions())) : [],
            childTable: $childTable,
            excludedFromTranslation: $field->getTranslationBehaviour() === FieldTranslationBehaviour::Excluded,
            accessControlled: $field->supportsAccessControl(),
        );
    }
}
