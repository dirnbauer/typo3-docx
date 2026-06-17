/**
 * Minimal client-side ICU MessageFormat resolver for XLIFF 2 labels.
 *
 * Supports the subset we actually use in TYPO3 backend labels:
 *   {var, plural, one {…} other {…}}    — locale-aware via Intl.PluralRules
 *   {var}                                 — simple variable substitution
 *   #                                     — current plural argument value
 *
 * Not a full ICU implementation (no select, selectordinal, offset, nested
 * plurals). Add cases here only when an actual label needs them.
 */

const PLURAL_HEADER_RE = /\{(\w+)\s*,\s*plural\s*,/;

/**
 * Find the first `{name, plural, ... }` arg, returning the full match span,
 * the arg name, and the inner body — with proper balanced-brace handling so
 * nested `{one}` / `{# others}` branches don't break the parse.
 *
 * @param {string} message
 */
function findPluralArg(message) {
  const m = message.match(PLURAL_HEADER_RE);
  if (!m) {
    return null;
  }
  const start = m.index;
  let i = start + m[0].length;
  let depth = 1;
  while (i < message.length && depth > 0) {
    const ch = message[i];
    if (ch === '{') {
      depth++;
    } else if (ch === '}') {
      depth--;
      if (depth === 0) {
        break;
      }
    }
    i++;
  }
  if (depth !== 0) {
    return null;
  }
  return {
    full: message.slice(start, i + 1),
    name: m[1],
    body: message.slice(start + m[0].length, i),
  };
}

/**
 * Parse a plural arg body — ` one {1 editor online} other {# editors online} `
 * — into a map { one: "1 editor online", other: "# editors online" }. Keys may
 * also be exact-match `=N` (e.g. `=0`).
 *
 * @param {string} body
 * @returns {Record<string, string>}
 */
function parsePluralBranches(body) {
  const branches = {};
  let i = 0;
  while (i < body.length) {
    while (i < body.length && /\s/.test(body[i])) {
      i++;
    }
    const keyMatch = body.slice(i).match(/^([a-z]+|=\d+)\s*\{/);
    if (!keyMatch) {
      break;
    }
    const key = keyMatch[1];
    i += keyMatch[0].length;
    let depth = 1;
    const start = i;
    while (i < body.length && depth > 0) {
      const ch = body[i];
      if (ch === '{') {
        depth++;
      } else if (ch === '}') {
        depth--;
        if (depth === 0) {
          break;
        }
      }
      i++;
    }
    branches[key] = body.slice(start, i);
    i++;
  }
  return branches;
}

/**
 * Format an ICU MessageFormat string against an args object and locale.
 *
 * @param {string} message
 * @param {Record<string, string | number>} args
 * @param {string} [locale='en']
 * @returns {string}
 */
export function formatIcu(message, args = {}, locale = 'en') {
  let out = message;

  const plural = findPluralArg(out);
  if (plural) {
    const value = Number(args[plural.name] ?? 0);
    const branches = parsePluralBranches(plural.body);
    const exactKey = `=${value}`;
    let branch = branches[exactKey];
    if (branch === undefined) {
      const category = new Intl.PluralRules(locale).select(value);
      branch = branches[category] ?? branches.other ?? '';
    }
    out = out.replace(plural.full, branch.replace(/#/g, String(value)));
  }

  return out.replace(/\{(\w+)\}/g, (_, key) =>
    key in args ? String(args[key]) : `{${key}}`,
  );
}
