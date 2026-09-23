import labels from '~labels/docx_editor.messages';

/**
 * Backend AJAX calls. Every function resolves to the decoded JSON envelope
 * (`{ok, ...}`) and throws when the route is not registered or `ok` is false;
 * the thrown error carries `httpStatus` (409 = revision conflict).
 */

function routeUrl(name) {
  const url = globalThis.TYPO3?.settings?.ajaxUrls?.[name];
  if (!url) {
    throw new Error(`${name} route is not registered.`);
  }
  return url;
}

async function requestJson(url, init = {}) {
  const response = await fetch(url, {
    credentials: 'same-origin',
    ...init,
    headers: {
      'X-Requested-With': 'XMLHttpRequest',
      Accept: 'application/json',
      ...(init.headers ?? {}),
    },
  });
  let data;
  try {
    data = await response.json();
  } catch {
    data = { ok: false, error: labels.get('error.invalidResponse') };
  }
  if (!data.ok) {
    const error = new Error(data.error || labels.get('error.requestFailed'));
    error.httpStatus = response.status;
    throw error;
  }
  return data;
}

/**
 * @param {string} route AJAX route name
 * @param {string | null} [override] URL that replaces the route (load-url / save-url)
 */
function endpoint(route, override = null) {
  return new URL(override || routeUrl(route), window.location.origin);
}

function getJson(route, params, override = null) {
  const url = endpoint(route, override);
  Object.entries(params).forEach(([key, value]) => {
    if (value !== '') {
      url.searchParams.set(key, value);
    }
  });
  return requestJson(url);
}

function postJson(route, body, init = {}, override = null) {
  return requestJson(endpoint(route, override), {
    ...init,
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

/** Resolves to {ok, data (base64 DOCX), revision}. */
export function loadDocument(fileIdentifier, url = null) {
  return getJson('docx_editor_document_load', { file: fileIdentifier }, url);
}

/** Posts {file, revision, data (base64 DOCX)}; resolves to {ok, revision}. */
export function saveDocument(fileIdentifier, revision, bytes, url = null) {
  return postJson(
    'docx_editor_document_save',
    {
      file: fileIdentifier,
      revision,
      data: encodeArrayBufferToBase64(bytes),
    },
    {},
    url,
  );
}

export function saveDocumentAs(folderIdentifier, fileName, bytes) {
  return postJson('docx_editor_document_save_as', {
    folder: folderIdentifier,
    fileName,
    data: encodeArrayBufferToBase64(bytes),
  });
}

export function fetchRevision(fileIdentifier) {
  return getJson('docx_editor_collab_revision', { file: fileIdentifier });
}

export function joinSession(fileIdentifier) {
  return postJson('docx_editor_collab_join', { file: fileIdentifier });
}

export function heartbeatSession(fileIdentifier, sessionUid) {
  return postJson('docx_editor_collab_heartbeat', { file: fileIdentifier, sessionUid });
}

/** Sent while the page unloads, hence keepalive. */
export function leaveSession(fileIdentifier, sessionUid) {
  return postJson('docx_editor_collab_leave', { file: fileIdentifier, sessionUid }, { keepalive: true });
}

export function encodeArrayBufferToBase64(buffer) {
  const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
  let binary = '';
  for (let i = 0; i < bytes.length; i += 0x8000) {
    binary += String.fromCharCode(...bytes.subarray(i, i + 0x8000));
  }
  return btoa(binary);
}

export function decodeBase64ToArrayBuffer(base64) {
  const binary = atob(base64);
  const bytes = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i);
  }
  return bytes.buffer;
}
