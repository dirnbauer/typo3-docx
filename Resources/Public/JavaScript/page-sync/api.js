import labels from '~labels/docx_editor.pagesync';

/**
 * The page round trip's AJAX routes. Every call resolves to the decoded
 * `{ok: true, …}` envelope and throws an Error with `httpStatus` and `code`
 * (the label key, e.g. "error.planOutdated") when the server refuses.
 */

function routeUrl(name) {
  const url = globalThis.TYPO3?.settings?.ajaxUrls?.[name];
  if (!url) {
    throw new Error(`${name} route is not registered.`);
  }
  return new URL(url, window.location.origin);
}

async function requestJson(url, init = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    ...init,
    headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json', ...(init.headers ?? {}) },
  });
  let data;
  try {
    data = await response.json();
  } catch {
    data = { ok: false, error: labels.get('ui.error.response') };
  }
  if (!data.ok) {
    const error = new Error(data.error || labels.get('ui.error.response'));
    error.httpStatus = response.status;
    error.code = data.code ?? '';
    throw error;
  }
  return data;
}

function post(name, body) {
  return requestJson(routeUrl(name), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

/** Resolves to {data (base64 DOCX), fileName, elements, skipped}. */
export function loadPage(page, language) {
  const url = routeUrl('docx_editor_page_load');
  url.searchParams.set('page', String(page));
  url.searchParams.set('language', String(language));
  return requestJson(url);
}

/**
 * @param {object} request {mode: "update", page, language} or {mode: "newPage", parent, split}
 * @param {Uint8Array|ArrayBuffer} bytes the document
 * @returns {Promise<{id: string, plans: object[]}>}
 */
export function previewImport(request, bytes) {
  return post('docx_editor_page_preview', { ...request, data: encodeBase64(bytes) });
}

/** Resolves to {results: [...], succeeded}. */
export function applyImport(id, decisions) {
  return post('docx_editor_page_apply', { id, decisions });
}

export function discardImport(id) {
  return post('docx_editor_page_discard', { id }).catch(() => {});
}

export function encodeBase64(buffer) {
  const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.length; i += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  }
  return btoa(binary);
}

export function decodeBase64(base64) {
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes;
}
