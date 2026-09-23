import { strFromU8, unzipSync } from 'fflate';

/**
 * Namespace-aware canonical form of the XML parts of a DOCX package, for
 * "nothing was lost" assertions that do not depend on serializer details.
 *
 * Canonical means: element and attribute names resolved to {namespace}local,
 * attributes sorted, namespace declarations and the XML declaration dropped,
 * entities decoded, whitespace-only text that only indents the markup
 * dropped. Paragraph and text IDs the engine assigns (w14:paraId,
 * w14:textId) and revision-session IDs (w:rsid*) are ignored: they identify
 * paragraphs, they carry no content.
 */

const XML_NS = 'http://www.w3.org/XML/1998/namespace';
const W14 = 'http://schemas.microsoft.com/office/word/2010/wordml';
const W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
const MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';

const IGNORED_ATTRIBUTES = new Set([`{${W14}}paraId`, `{${W14}}textId`]);

/**
 * @param {Uint8Array} bytes DOCX package
 * @returns {Map<string, string>} part name → canonical XML, or "binary:<length>:<hash>" for other parts
 */
export function canonicalPackage(bytes) {
  const files = unzipSync(bytes);
  const parts = new Map();
  for (const [name, content] of Object.entries(files)) {
    if (name.endsWith('/')) {
      continue;
    }
    parts.set(name, /\.(xml|rels)$/i.test(name) ? canonicalXml(strFromU8(content)) : `binary:${content.length}:${fnv1a(content)}`);
  }
  return parts;
}

/**
 * Differences between two canonical packages, as readable messages.
 *
 * @param {Map<string, string>} expected
 * @param {Map<string, string>} actual
 * @param {{ignoreParts?: RegExp}} [options]
 * @returns {string[]}
 */
export function packageDifferences(expected, actual, options = {}) {
  const differences = [];
  for (const [name, content] of expected) {
    if (options.ignoreParts?.test(name)) {
      continue;
    }
    if (!actual.has(name)) {
      differences.push(`${name}: missing after save`);
    } else if (actual.get(name) !== content) {
      differences.push(`${name}: ${firstDifference(content, actual.get(name))}`);
    }
  }
  for (const name of actual.keys()) {
    if (!expected.has(name) && !options.ignoreParts?.test(name)) {
      differences.push(`${name}: added by save`);
    }
  }
  return differences;
}

export function canonicalXml(xml) {
  const out = [];
  const scopes = [{ '': '', xml: XML_NS }];
  const tokens = /<!--[\s\S]*?-->|<\?[\s\S]*?\?>|<!\[CDATA\[([\s\S]*?)\]\]>|<(\/?)([\w.:-]+)((?:\s+[\w.:-]+\s*=\s*(?:"[^"]*"|'[^']*'))*)\s*(\/?)>|([^<]+)/g;
  for (const match of xml.replace(/^﻿/, '').matchAll(tokens)) {
    const [whole, cdata, closing, name, attributes, selfClosing, text] = match;
    if (text !== undefined || cdata !== undefined) {
      const value = cdata ?? decode(text);
      if (cdata !== undefined || !/^\s*$/.test(value) || !/\n/.test(value)) {
        out.push(`#${JSON.stringify(value)}`);
      }
      continue;
    }
    if (name === undefined || whole.startsWith('<!--') || whole.startsWith('<?')) {
      continue;
    }
    if (closing === '/') {
      scopes.pop();
      out.push(')');
      continue;
    }
    const scope = { ...scopes[scopes.length - 1] };
    const pairs = [];
    for (const [, key, , doubleQuoted, singleQuoted] of attributes.matchAll(/([\w.:-]+)\s*=\s*("([^"]*)"|'([^']*)')/g)) {
      const value = decode(doubleQuoted ?? singleQuoted);
      if (key === 'xmlns') {
        scope[''] = value;
      } else if (key.startsWith('xmlns:')) {
        scope[key.slice(6)] = value;
      } else {
        pairs.push([key, value]);
      }
    }
    const resolved = pairs
      .map(([key, value]) => [expand(key, scope, false), key === 'mc:Ignorable' || expand(key, scope, false) === `{${MC}}Ignorable` ? ignorable(value, scope) : value])
      .filter(([key]) => !IGNORED_ATTRIBUTES.has(key) && !key.startsWith(`{${W}}rsid`))
      .sort(([a], [b]) => (a < b ? -1 : a > b ? 1 : 0));
    out.push(`(${expand(name, scope, true)}${resolved.map(([key, value]) => ` ${key}=${JSON.stringify(value)}`).join('')}`);
    if (selfClosing === '/') {
      out.push(')');
    } else {
      scopes.push(scope);
    }
  }
  return out.join('');
}

function expand(qualified, scope, isElement) {
  const colon = qualified.indexOf(':');
  if (colon === -1) {
    return isElement && scope[''] ? `{${scope['']}}${qualified}` : qualified;
  }
  const prefix = qualified.slice(0, colon);
  const uri = scope[prefix];
  return `{${uri ?? `unbound:${prefix}`}}${qualified.slice(colon + 1)}`;
}

/** mc:Ignorable lists prefixes; compare the namespaces they stand for. */
function ignorable(value, scope) {
  return value
    .split(/\s+/)
    .filter(Boolean)
    .map((prefix) => scope[prefix] ?? `unbound:${prefix}`)
    .sort()
    .join(' ');
}

function decode(value) {
  return value.replace(/&(#x[0-9a-f]+|#\d+|lt|gt|amp|quot|apos);/gi, (_, entity) => {
    switch (entity.toLowerCase()) {
      case 'lt':
        return '<';
      case 'gt':
        return '>';
      case 'amp':
        return '&';
      case 'quot':
        return '"';
      case 'apos':
        return "'";
      default:
        return String.fromCodePoint(entity[1].toLowerCase() === 'x' ? parseInt(entity.slice(2), 16) : parseInt(entity.slice(1), 10));
    }
  });
}

function firstDifference(expected, actual) {
  let index = 0;
  while (index < expected.length && expected[index] === actual[index]) {
    index += 1;
  }
  const from = Math.max(0, index - 80);
  return `differs at ${index}\n  expected …${expected.slice(from, index + 160)}\n  actual   …${actual.slice(from, index + 160)}`;
}

function fnv1a(bytes) {
  let hash = 0x811c9dc5;
  for (const byte of bytes) {
    hash ^= byte;
    hash = Math.imul(hash, 0x01000193) >>> 0;
  }
  return hash.toString(16);
}
