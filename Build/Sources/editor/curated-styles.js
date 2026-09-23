import { strFromU8, strToU8, unzipSync, zipSync } from 'fflate';

/**
 * The TYPO3 style set: Normal plus Heading 1-4.
 *
 * Word identifies built-in styles by NAME ("Normal", "heading 1"); the style
 * ID is localized ("Standard" and "berschrift1" in a German Word). The picker
 * therefore maps roles to whatever ID the document uses.
 *
 * Word also keeps unused headings LATENT: styles.xml does not define them
 * until they are first applied, and the engine refuses a style the document
 * does not define. materializeCuratedStyles() adds Word's definition for each
 * missing heading when a document is opened, and dropUnusedMaterializedStyles()
 * takes the ones nobody applied out again before the bytes are saved, so an
 * untouched document is saved without them.
 */
export const CURATED_ROLES = Object.freeze(['normal', 'heading1', 'heading2', 'heading3', 'heading4']);

const WORDPROCESSINGML = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const STYLES_RELATIONSHIP = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles';

/**
 * Word's own heading definitions (Office theme, Word 2016 onwards). Fonts are
 * inherited on purpose: naming the theme's heading face would raise the
 * font notice for a style nobody uses.
 */
const HEADING_DEFINITIONS = {
  1: { before: 240, color: '2F5496', shade: 'BF', halfPoints: 32, italic: false },
  2: { before: 40, color: '2F5496', shade: 'BF', halfPoints: 26, italic: false },
  3: { before: 40, color: '1F3763', shade: '7F', halfPoints: 24, italic: false },
  4: { before: 40, color: '2F5496', shade: 'BF', halfPoints: null, italic: true },
};

/**
 * @param {string} name w:name of a paragraph style (or its ID)
 * @returns {string | null} curated role
 */
export function roleOfStyleName(name) {
  const normalized = String(name).trim().toLowerCase();
  if (normalized === 'normal') {
    return 'normal';
  }
  const heading = /^heading ?([1-4])$/.exec(normalized);
  return heading ? `heading${heading[1]}` : null;
}

/**
 * The curated picker options, in role order, for the paragraph styles a
 * document defines (editor.getDocumentStyles()).
 *
 * @param {ReadonlyArray<{styleId: string, name: string, type: string}>} documentStyles
 * @returns {Array<{role: string, styleId: string}>}
 */
export function curatedStyleOptions(documentStyles) {
  const byRole = new Map();
  for (const style of documentStyles) {
    if (style.type !== 'paragraph') {
      continue;
    }
    const role = roleOfStyleName(style.name || style.styleId) ?? roleOfStyleName(style.styleId);
    if (role !== null && !byRole.has(role)) {
      byRole.set(role, style.styleId);
    }
  }
  return CURATED_ROLES.filter((role) => byRole.has(role)).map((role) => ({ role, styleId: byRole.get(role) }));
}

/**
 * @param {Uint8Array} bytes DOCX package
 * @returns {{bytes: Uint8Array, injected: string[]}} the package with the
 *   missing Heading 1-4 definitions, and the style IDs that were added
 */
export function materializeCuratedStyles(bytes) {
  const unchanged = { bytes, injected: [] };
  const files = unzip(bytes);
  const path = files ? stylesPartPath(files) : null;
  if (path === null) {
    return unchanged;
  }
  const xml = strFromU8(files[path]);
  const prefix = wordPrefix(xml);
  if (prefix === null) {
    return unchanged;
  }

  const styles = styleHeads(xml, prefix);
  const roles = new Set(styles.filter((style) => style.type === 'paragraph').map((style) => style.role));
  const ids = new Set(styles.map((style) => style.styleId));
  const normal = styles.find((style) => style.type === 'paragraph' && style.role === 'normal')
    ?? styles.find((style) => style.type === 'paragraph' && style.isDefault);

  const injected = [];
  let definitions = '';
  for (const level of [1, 2, 3, 4]) {
    const styleId = `Heading${level}`;
    if (roles.has(`heading${level}`) || ids.has(styleId)) {
      continue;
    }
    definitions += headingDefinition(prefix, level, styleId, normal?.styleId ?? null);
    injected.push(styleId);
  }
  const close = `</${prefix}styles>`;
  const at = xml.lastIndexOf(close);
  if (injected.length === 0 || at === -1) {
    return unchanged;
  }
  files[path] = strToU8(xml.slice(0, at) + definitions + xml.slice(at));
  return { bytes: zipSync(files), injected };
}

/**
 * Removes materialized heading definitions that no paragraph, list level or
 * other style refers to.
 *
 * @param {Uint8Array} bytes DOCX package as saved by the editor
 * @param {ReadonlyArray<string>} injected style IDs materializeCuratedStyles() added
 * @returns {Uint8Array}
 */
export function dropUnusedMaterializedStyles(bytes, injected) {
  if (injected.length === 0) {
    return bytes;
  }
  const files = unzip(bytes);
  const path = files ? stylesPartPath(files) : null;
  if (path === null) {
    return bytes;
  }
  let xml = strFromU8(files[path]);
  const prefix = wordPrefix(xml);
  if (prefix === null) {
    return bytes;
  }

  const otherParts = Object.keys(files)
    .filter((name) => name !== path && /^word\/.*\.xml$/.test(name))
    .map((name) => strFromU8(files[name]));
  let changed = false;
  for (const styleId of injected) {
    const definition = styleElement(xml, prefix, styleId);
    if (definition === null) {
      continue;
    }
    const rest = xml.replace(definition, '');
    const reference = new RegExp(`<[\\w.-]+:(?:pStyle|basedOn|next|link)\\b[^>]*?\\b[\\w.-]+:val="${escapeRegExp(styleId)}"`);
    if (reference.test(rest) || otherParts.some((part) => reference.test(part))) {
      continue;
    }
    xml = rest;
    changed = true;
  }
  if (!changed) {
    return bytes;
  }
  files[path] = strToU8(xml);
  return zipSync(files);
}

function unzip(bytes) {
  try {
    return unzipSync(bytes instanceof Uint8Array ? bytes : new Uint8Array(bytes));
  } catch {
    return null;
  }
}

/** The styles part the main document relates to (word/styles.xml by convention). */
function stylesPartPath(files) {
  const rels = files['word/_rels/document.xml.rels'];
  if (rels) {
    const match = new RegExp(`<Relationship\\b[^>]*Type="${escapeRegExp(STYLES_RELATIONSHIP)}"[^>]*>`).exec(strFromU8(rels));
    const target = match ? /Target="([^"]+)"/.exec(match[0])?.[1] : undefined;
    if (target !== undefined) {
      const path = target.startsWith('/') ? target.slice(1) : `word/${target}`;
      return files[path] ? path : null;
    }
  }
  return files['word/styles.xml'] ? 'word/styles.xml' : null;
}

/** "w:" when the root binds a prefix to WordprocessingML (transitional), else null. */
function wordPrefix(xml) {
  const root = /<([\w.-]+):styles\b[^>]*>/.exec(xml);
  if (root === null) {
    return null;
  }
  const declared = new RegExp(`xmlns:${escapeRegExp(root[1])}="([^"]+)"`).exec(root[0]);
  return declared?.[1] === WORDPROCESSINGML ? `${root[1]}:` : null;
}

function styleHeads(xml, prefix) {
  const heads = [];
  const pattern = new RegExp(`<${escapeRegExp(prefix)}style\\b([^>]*?)(/?)>`, 'g');
  for (const match of xml.matchAll(pattern)) {
    const attributes = match[1];
    const styleId = attribute(attributes, `${prefix}styleId`) ?? '';
    const type = attribute(attributes, `${prefix}type`) ?? 'paragraph';
    const isDefault = ['1', 'true', 'on'].includes(attribute(attributes, `${prefix}default`) ?? '');
    let name = '';
    if (match[2] === '') {
      const end = xml.indexOf(`</${prefix}style>`, match.index);
      const body = xml.slice(match.index, end === -1 ? undefined : end);
      name = attribute(new RegExp(`<${escapeRegExp(prefix)}name\\b([^>]*)>`).exec(body)?.[1] ?? '', `${prefix}val`) ?? '';
    }
    heads.push({ styleId, type, isDefault, role: roleOfStyleName(name || styleId) ?? roleOfStyleName(styleId) });
  }
  return heads;
}

function styleElement(xml, prefix, styleId) {
  const p = escapeRegExp(prefix);
  const pattern = new RegExp(`<${p}style\\b[^>]*?\\b${p}styleId="${escapeRegExp(styleId)}"[^>]*?(?:/>|>[\\s\\S]*?</${p}style>)`);
  return pattern.exec(xml)?.[0] ?? null;
}

function headingDefinition(w, level, styleId, normalId) {
  const heading = HEADING_DEFINITIONS[level];
  const based = normalId === null ? '' : `<${w}basedOn ${w}val="${normalId}"/><${w}next ${w}val="${normalId}"/>`;
  const size = heading.halfPoints === null ? '' : `<${w}sz ${w}val="${heading.halfPoints}"/><${w}szCs ${w}val="${heading.halfPoints}"/>`;
  return (
    `<${w}style ${w}type="paragraph" ${w}styleId="${styleId}">` +
    `<${w}name ${w}val="heading ${level}"/>${based}` +
    `<${w}uiPriority ${w}val="9"/>${level > 1 ? `<${w}unhideWhenUsed/>` : ''}<${w}qFormat/>` +
    `<${w}pPr><${w}keepNext/><${w}keepLines/><${w}spacing ${w}before="${heading.before}" ${w}after="0"/>` +
    `<${w}outlineLvl ${w}val="${level - 1}"/></${w}pPr>` +
    `<${w}rPr>${heading.italic ? `<${w}i/><${w}iCs/>` : ''}` +
    `<${w}color ${w}val="${heading.color}" ${w}themeColor="accent1" ${w}themeShade="${heading.shade}"/>${size}</${w}rPr>` +
    `</${w}style>`
  );
}

function attribute(attributes, name) {
  return new RegExp(`(?:^|\\s)${escapeRegExp(name)}="([^"]*)"`).exec(attributes)?.[1] ?? null;
}

function escapeRegExp(value) {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
