<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Manifest;

use TYPO3\CMS\Core\Crypto\HashAlgo;
use TYPO3\CMS\Core\Crypto\HashService;
use Webconsulting\DocxEditor\PageSync\Ooxml\DocxArchive;
use Webconsulting\DocxEditor\PageSync\Ooxml\Ns;
use Webconsulting\DocxEditor\PageSync\Ooxml\SafeXml;

/**
 * Writes the manifest into a custom XML part and finds it again.
 *
 * The part is found by its root namespace, never by its name: Word renumbers custom XML parts
 * (customXml/item1.xml, item2.xml …) when it saves. The signature is an HMAC over the manifest
 * data rather than the XML text, so re-serialisation by an editor does not invalidate it.
 */
final readonly class ManifestXml
{
    private const string SECRET = 'docx-editor-page-sync-manifest';

    public function __construct(
        private HashService $hashService,
    ) {}

    public function toXml(RoundTripManifest $manifest): string
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(RoundTripManifest::NAMESPACE, 't3:roundtrip');
        $document->appendChild($root);
        $root->setAttribute('version', (string)RoundTripManifest::VERSION);
        $root->setAttribute('signature', $this->sign($manifest));

        $page = $document->createElementNS(RoundTripManifest::NAMESPACE, 't3:page');
        $page->setAttribute('uid', (string)$manifest->pageUid);
        $page->setAttribute('site', $manifest->siteIdentifier);
        $page->setAttribute('language', (string)$manifest->language);
        $page->setAttribute('workspace', (string)$manifest->workspace);
        $page->setAttribute('exported', $manifest->exportedAt->format(\DateTimeInterface::ATOM));
        $page->setAttribute('exportedBy', (string)$manifest->exportedBy);
        $root->appendChild($page);

        foreach ($manifest->records as $record) {
            $element = $document->createElementNS(RoundTripManifest::NAMESPACE, 't3:record');
            $element->setAttribute('table', $record->table);
            $element->setAttribute('uid', (string)$record->uid);
            $element->setAttribute('reference', (string)$record->reference);
            $element->setAttribute('position', (string)$record->position);
            $element->setAttribute('language', (string)$record->language);
            if ($record->type !== '') {
                $element->setAttribute('type', $record->type);
            }
            if ($record->table === 'tt_content') {
                $element->setAttribute('colPos', (string)$record->colPos);
            }
            if ($record->parent !== '') {
                $element->setAttribute('parent', $record->parent);
                $element->setAttribute('parentField', $record->parentField);
            }
            if ($record->locked) {
                $element->setAttribute('locked', '1');
            }
            if ($record->translationSource) {
                $element->setAttribute('translationSource', '1');
            }
            if ($record->fingerprint !== '') {
                $element->setAttribute('fingerprint', $record->fingerprint);
            }
            foreach ($record->fields as $field) {
                $fieldElement = $document->createElementNS(RoundTripManifest::NAMESPACE, 't3:field');
                $fieldElement->setAttribute('name', $field->name);
                $fieldElement->setAttribute('hash', $field->hash);
                $fieldElement->setAttribute('reference', (string)$field->reference);
                if ($field->level > 0) {
                    $fieldElement->setAttribute('level', (string)$field->level);
                }
                if ($field->value !== '') {
                    $fieldElement->setAttribute('value', $field->value);
                }
                $element->appendChild($fieldElement);
            }
            $root->appendChild($element);
        }

        foreach ($manifest->pictures as $picture) {
            $element = $document->createElementNS(RoundTripManifest::NAMESPACE, 't3:picture');
            $element->setAttribute('reference', (string)$picture->reference);
            $element->setAttribute('file', (string)$picture->file);
            $element->setAttribute('sha1', $picture->sha1);
            $element->setAttribute('embedded', $picture->embedded);
            $root->appendChild($element);
        }

        return (string)$document->saveXML();
    }

    /**
     * The item properties part Word expects next to a custom XML part.
     */
    public function itemPropertiesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="no"?>' . "\n"
            . '<ds:datastoreItem ds:itemID="{' . self::itemId() . '}" xmlns:ds="' . Ns::CUSTOM_XML_DATASTORE . '">'
            . '<ds:schemaRefs><ds:schemaRef ds:uri="' . RoundTripManifest::NAMESPACE . '"/></ds:schemaRefs>'
            . '</ds:datastoreItem>';
    }

    public function find(DocxArchive $archive, string $mainPart): ?RoundTripManifest
    {
        $candidates = [];
        foreach ($archive->relationships($mainPart) as $relationship) {
            if (!$relationship->external && $relationship->type === Ns::REL_CUSTOM_XML) {
                $candidates[] = $relationship->target;
            }
        }
        foreach ($archive->partNames() as $partName) {
            if (preg_match('#^customxml/item[0-9]*\.xml$#i', $partName) === 1) {
                $candidates[] = $partName;
            }
        }

        foreach (array_unique($candidates) as $partName) {
            if (!$archive->has($partName)) {
                continue;
            }
            $xml = $archive->read($partName);
            if (!str_contains($xml, RoundTripManifest::NAMESPACE)) {
                continue;
            }
            $manifest = $this->fromXml(SafeXml::load($xml, $partName));
            if ($manifest !== null) {
                return $manifest;
            }
        }

        return null;
    }

    public function fromXml(\DOMDocument $document): ?RoundTripManifest
    {
        $root = $document->documentElement;
        if ($root === null || $root->namespaceURI !== RoundTripManifest::NAMESPACE || $root->localName !== 'roundtrip') {
            return null;
        }

        $page = null;
        foreach ($root->getElementsByTagNameNS(RoundTripManifest::NAMESPACE, 'page') as $candidate) {
            $page = $candidate;
            break;
        }
        if ($page === null) {
            return null;
        }
        $exportedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $page->getAttribute('exported'));

        $records = [];
        foreach ($root->getElementsByTagNameNS(RoundTripManifest::NAMESPACE, 'record') as $element) {
            $table = $element->getAttribute('table');
            $uid = (int)$element->getAttribute('uid');
            if ($table === '' || $uid <= 0) {
                continue;
            }
            $fields = [];
            foreach ($element->getElementsByTagNameNS(RoundTripManifest::NAMESPACE, 'field') as $fieldElement) {
                $name = $fieldElement->getAttribute('name');
                if ($name === '') {
                    continue;
                }
                $fields[$name] = new ManifestField(
                    $name,
                    $fieldElement->getAttribute('hash'),
                    (int)$fieldElement->getAttribute('level'),
                    (int)$fieldElement->getAttribute('reference'),
                    $fieldElement->getAttribute('value'),
                );
            }
            $record = new ManifestRecord(
                table: $table,
                uid: $uid,
                fields: $fields,
                type: $element->getAttribute('type'),
                colPos: (int)$element->getAttribute('colPos'),
                position: (int)$element->getAttribute('position'),
                parent: $element->getAttribute('parent'),
                parentField: $element->getAttribute('parentField'),
                language: (int)$element->getAttribute('language'),
                locked: $element->getAttribute('locked') === '1',
                translationSource: $element->getAttribute('translationSource') === '1',
                fingerprint: $element->getAttribute('fingerprint'),
                reference: (int)$element->getAttribute('reference'),
            );
            $records[$record->key()] = $record;
        }

        $pictures = [];
        foreach ($root->getElementsByTagNameNS(RoundTripManifest::NAMESPACE, 'picture') as $element) {
            $reference = (int)$element->getAttribute('reference');
            $file = (int)$element->getAttribute('file');
            $sha1 = $element->getAttribute('sha1');
            $embedded = $element->getAttribute('embedded');
            if ($reference > 0 && $file > 0 && $sha1 !== '' && $embedded !== '') {
                $pictures[] = new ManifestPicture($reference, $file, $sha1, $embedded);
            }
        }

        $manifest = new RoundTripManifest(
            pageUid: (int)$page->getAttribute('uid'),
            siteIdentifier: $page->getAttribute('site'),
            language: (int)$page->getAttribute('language'),
            workspace: (int)$page->getAttribute('workspace'),
            exportedAt: $exportedAt === false ? new \DateTimeImmutable('@0') : $exportedAt,
            records: $records,
            exportedBy: (int)$page->getAttribute('exportedBy'),
            pictures: $pictures,
        );

        $signature = $root->getAttribute('signature');

        return $manifest->withTrust($signature !== '' && hash_equals($this->sign($manifest), $signature));
    }

    private function sign(RoundTripManifest $manifest): string
    {
        return $this->hashService->hmac(
            json_encode($manifest->toCanonicalArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            self::SECRET,
            HashAlgo::SHA256,
        );
    }

    private static function itemId(): string
    {
        $hex = bin2hex(random_bytes(16));

        return strtoupper(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }
}
