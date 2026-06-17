import assert from 'node:assert/strict';
import test from 'node:test';
import { formatIcu } from './docx-icu-format.js';

const COLLAB = '{count, plural, one {1 editor online} other {# editors online}}';
const COLLAB_DE = '{count, plural, one {1 Bearbeiter online} other {# Bearbeiter online}}';

test('plural: one branch picked for count=1', () => {
  assert.equal(formatIcu(COLLAB, { count: 1 }, 'en'), '1 editor online');
  assert.equal(formatIcu(COLLAB_DE, { count: 1 }, 'de'), '1 Bearbeiter online');
});

test('plural: other branch with # substitution', () => {
  assert.equal(formatIcu(COLLAB, { count: 3 }, 'en'), '3 editors online');
  assert.equal(formatIcu(COLLAB_DE, { count: 7 }, 'de'), '7 Bearbeiter online');
});

test('plural: 0 uses other branch', () => {
  assert.equal(formatIcu(COLLAB, { count: 0 }, 'en'), '0 editors online');
});

test('plural: exact-match =0 wins over category', () => {
  const msg = '{count, plural, =0 {nobody} one {one} other {# others}}';
  assert.equal(formatIcu(msg, { count: 0 }, 'en'), 'nobody');
  assert.equal(formatIcu(msg, { count: 1 }, 'en'), 'one');
  assert.equal(formatIcu(msg, { count: 5 }, 'en'), '5 others');
});

test('variable substitution outside plural', () => {
  assert.equal(formatIcu('Saved to {path}', { path: 'fileadmin/x.docx' }), 'Saved to fileadmin/x.docx');
});

test('unknown variable left as literal placeholder', () => {
  assert.equal(formatIcu('Hi {name}', {}), 'Hi {name}');
});
