<?php

declare(strict_types=1);

namespace Webconsulting\DocxEditor\PageSync\Ooxml;

/**
 * The XML namespaces and relationship types of the OOXML subset the round trip reads and writes.
 */
final class Ns
{
    public const string W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const string R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    public const string WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    public const string A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    public const string PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
    public const string V = 'urn:schemas-microsoft-com:vml';
    public const string O = 'urn:schemas-microsoft-com:office:office';
    public const string MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';
    public const string M = 'http://schemas.openxmlformats.org/officeDocument/2006/math';
    public const string W14 = 'http://schemas.microsoft.com/office/word/2010/wordml';
    public const string PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    public const string CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';
    public const string CORE_PROPERTIES = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';
    public const string DC = 'http://purl.org/dc/elements/1.1/';
    public const string DCTERMS = 'http://purl.org/dc/terms/';
    public const string XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    public const string EXTENDED_PROPERTIES = 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties';
    public const string CUSTOM_PROPERTIES = 'http://schemas.openxmlformats.org/officeDocument/2006/custom-properties';
    public const string VT = 'http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes';
    public const string CUSTOM_XML_DATASTORE = 'http://schemas.openxmlformats.org/officeDocument/2006/customXml';

    public const string REL_OFFICE_DOCUMENT = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';
    /** The Strict-conformance variant Word writes when a document is saved as "Strict Open XML". */
    public const string REL_OFFICE_DOCUMENT_STRICT = 'http://purl.oclc.org/ooxml/officeDocument/relationships/officeDocument';
    public const string REL_STYLES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';
    public const string REL_NUMBERING = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering';
    public const string REL_SETTINGS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/settings';
    public const string REL_HYPERLINK = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink';
    public const string REL_IMAGE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image';
    public const string REL_CUSTOM_XML = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXml';
    public const string REL_CUSTOM_XML_PROPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/customXmlProps';
    public const string REL_CORE_PROPERTIES = 'http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties';
    public const string REL_EXTENDED_PROPERTIES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties';
    public const string REL_CUSTOM_PROPERTIES = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/custom-properties';
    public const string REL_FONT_TABLE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/fontTable';
    public const string REL_THEME = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/theme';

    public const string CT_DOCUMENT = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml';
    public const string CT_TEMPLATE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.template.main+xml';
    public const string CT_STYLES = 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml';
    public const string CT_NUMBERING = 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml';
    public const string CT_SETTINGS = 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml';
    public const string CT_FONT_TABLE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.fontTable+xml';
    public const string CT_THEME = 'application/vnd.openxmlformats-officedocument.theme+xml';
    public const string CT_CORE_PROPERTIES = 'application/vnd.openxmlformats-package.core-properties+xml';
    public const string CT_EXTENDED_PROPERTIES = 'application/vnd.openxmlformats-officedocument.extended-properties+xml';
    public const string CT_CUSTOM_PROPERTIES = 'application/vnd.openxmlformats-officedocument.custom-properties+xml';
    public const string CT_CUSTOM_XML_PROPS = 'application/vnd.openxmlformats-officedocument.customXmlProperties+xml';
    public const string CT_RELATIONSHIPS = 'application/vnd.openxmlformats-package.relationships+xml';

    /** The format id Word uses for user-defined custom document properties. */
    public const string CUSTOM_PROPERTY_FMTID = '{D5CDD505-2E9C-101B-9397-08002B2CF9AE}';
}
