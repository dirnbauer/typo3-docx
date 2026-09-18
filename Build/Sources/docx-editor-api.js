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
    data = { ok: false, error: 'Invalid server response.' };
  }
  if (!data.ok) {
    const error = new Error(data.error || response.statusText || 'Request failed.');
    error.httpStatus = response.status;
    throw error;
  }
  return data;
}

function getJson(route, params) {
  const url = new URL(routeUrl(route), window.location.origin);
  Object.entries(params).forEach(([key, value]) => url.searchParams.set(key, value));
  return requestJson(url);
}

function postJson(route, body) {
  return requestJson(routeUrl(route), {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body),
  });
}

export function loadDocument(fileIdentifier) {
  return getJson('docx_editor_document_load', { file: fileIdentifier });
}

export function saveDocument(fileIdentifier, revision, arrayBuffer) {
  return postJson('docx_editor_document_save', {
    file: fileIdentifier,
    revision,
    data: encodeArrayBufferToBase64(arrayBuffer),
  });
}

export function saveDocumentAs(folderIdentifier, fileName, arrayBuffer) {
  return postJson('docx_editor_document_save_as', {
    folder: folderIdentifier,
    fileName,
    data: encodeArrayBufferToBase64(arrayBuffer),
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

export function leaveSession(fileIdentifier, sessionUid) {
  return postJson('docx_editor_collab_leave', { file: fileIdentifier, sessionUid });
}

export function encodeArrayBufferToBase64(arrayBuffer) {
  const bytes = new Uint8Array(arrayBuffer);
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
